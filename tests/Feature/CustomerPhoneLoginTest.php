<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\Customer;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\CustomerLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Layar masuk satu halaman untuk pelanggan dan karyawan: email atau No. HP. Email pelanggan atau yang belum terdaftar menuju kode
 * verifikasi; No. HP pelanggan memakai kode yang dikirim ke email akunnya; email atau No. HP karyawan menuju password.
 */
class CustomerPhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Mail::fake();
    }

    private function customer(string $email, string $phone): Customer
    {
        return Customer::create(['name' => 'Juliana', 'email' => $email, 'phone_number' => $phone, 'email_verified_at' => now()]);
    }

    public function test_resolve_email_understands_the_ways_a_number_is_written(): void
    {
        $this->customer('juliana@gmail.com', '81234567890');

        foreach (['081234567890', '+62 812-3456-7890', '6281234567890', '81234567890'] as $written) {
            $this->assertSame('juliana@gmail.com', CustomerLogin::resolveEmail($written), $written);
        }

        $this->assertNull(CustomerLogin::resolveEmail('085555555555'), 'Nomor tak dikenal tidak diterjemahkan.');
        $this->assertSame('baru@gmail.com', CustomerLogin::resolveEmail('baru@gmail.com'), 'Email dipakai apa adanya (termasuk yang belum terdaftar).');
        $this->assertSame('ju***@gmail.com', CustomerLogin::maskEmail('juliana@gmail.com'));
        $this->assertSame('a***@gmail.com', CustomerLogin::maskEmail('ab@gmail.com'));
    }

    public function test_detect_role_sends_customer_phones_to_verification_and_unknown_phones_to_an_email_prompt(): void
    {
        $this->customer('juliana@gmail.com', '81234567890');

        $this->postJson('/api/auth/detect-role', ['login' => '081234567890'])->assertJson(['role' => 'customer']);
        $this->postJson('/api/auth/detect-role', ['login' => 'belum.terdaftar@gmail.com'])->assertJson(['role' => 'customer']);
        $this->postJson('/api/auth/detect-role', ['login' => '085555555555'])->assertJson(['role' => 'unknown']);
    }

    public function test_a_staff_phone_or_email_goes_to_the_password_step_even_if_a_customer_shares_the_number(): void
    {
        $staff = User::create(['name' => 'Budi', 'email' => 'budi@ginnva.test', 'password' => 'rahasia123', 'phone' => '081299998888', 'is_active' => true]);
        $this->customer('pelanggan@gmail.com', '81299998888');

        $this->postJson('/api/auth/detect-role', ['login' => '081299998888'])->assertJson(['role' => 'staff']);
        $this->postJson('/api/auth/detect-role', ['login' => $staff->email])->assertJson(['role' => 'staff']);
    }

    public function test_requesting_a_code_by_phone_emails_the_account_and_returns_a_masked_hint(): void
    {
        $this->customer('juliana@gmail.com', '81234567890');

        $this->postJson('/api/customer/auth/request-otp', ['login' => '0812 3456 7890'])
            ->assertOk()
            ->assertJsonPath('email_hint', 'ju***@gmail.com');

        Mail::assertSent(OtpMail::class, fn ($mail) => $mail->hasTo('juliana@gmail.com'));
    }

    public function test_an_unknown_phone_cannot_request_a_code_and_is_told_to_use_email(): void
    {
        $this->postJson('/api/customer/auth/request-otp', ['login' => '085555555555'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nomor ini belum terdaftar. Masukkan email untuk daftar atau masuk.');

        Mail::assertNothingSent();
    }

    public function test_an_unregistered_email_still_gets_a_code_and_the_old_app_payload_keeps_working(): void
    {
        $this->postJson('/api/customer/auth/request-otp', ['login' => 'baru@gmail.com'])->assertOk();
        $this->postJson('/api/customer/auth/request-otp', ['email' => 'lama@gmail.com'])->assertOk();
        $this->postJson('/api/customer/auth/request-otp', ['email' => 'bukan-email'])->assertStatus(422);

        Mail::assertSent(OtpMail::class, fn ($mail) => $mail->hasTo('baru@gmail.com'));
        Mail::assertSent(OtpMail::class, fn ($mail) => $mail->hasTo('lama@gmail.com'));
    }

    public function test_verifying_with_the_phone_logs_in_the_existing_account_without_creating_a_new_one(): void
    {
        $customer = $this->customer('juliana@gmail.com', '81234567890');
        OtpCode::generateFor('juliana@gmail.com', 'email');
        $code = OtpCode::where('identifier', 'juliana@gmail.com')->where('channel', 'email')->latest('id')->value('code');

        $this->postJson('/api/customer/auth/verify-otp', ['login' => '081234567890', 'code' => '000000'])->assertStatus(422);

        $response = $this->postJson('/api/customer/auth/verify-otp', ['login' => '081234567890', 'code' => $code])->assertSuccessful();

        $this->assertFalse($response->json('is_new'));
        $this->assertSame($customer->id, $response->json('data.id'));
        $this->assertSame(1, Customer::count());
    }

    public function test_verifying_an_unknown_phone_is_refused(): void
    {
        $this->postJson('/api/customer/auth/verify-otp', ['login' => '085555555555', 'code' => '123456'])->assertStatus(422);
        $this->assertSame(0, Customer::count());
    }
}
