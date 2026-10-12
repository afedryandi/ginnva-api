{{-- Tur pengenalan panel admin: Alpine murni, tanpa Livewire. Penandaan selesai lewat GET biasa. --}}
<div
    x-data="{
        open: true,
        step: 0,
        steps: @js(\App\Support\AdminTour::steps()),
        doneUrl: @js(url('/admin-tour/selesai')),
        finish() {
            this.open = false;
            fetch(this.doneUrl, { credentials: 'same-origin' }).catch(() => {});
        },
        next() { this.step < this.steps.length - 1 ? this.step++ : this.finish(); },
        prev() { if (this.step > 0) this.step--; },
    }"
    x-show="open"
    x-cloak
    x-on:keydown.escape.window="finish()"
    style="position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0, 0, 0, 0.5);"
    role="dialog"
    aria-modal="true"
    aria-labelledby="admin-tour-title"
>
    <div
        class="bg-white text-gray-950 dark:bg-gray-900 dark:text-white shadow-xl ring-1 ring-gray-950/5 dark:ring-white/10"
        style="width: 100%; max-width: 28rem; border-radius: 0.75rem; padding: 1.5rem;"
    >
        <p style="font-size: 0.75rem; opacity: 0.7; margin-bottom: 0.25rem;" x-text="'Langkah ' + (step + 1) + ' dari ' + steps.length"></p>
        <h2 id="admin-tour-title" style="font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem;" x-text="steps[step].title"></h2>
        <p style="font-size: 0.875rem; line-height: 1.5rem;" x-text="steps[step].body"></p>

        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-top: 1.25rem;">
            <button type="button" x-on:click="finish()" style="font-size: 0.875rem; opacity: 0.7; background: none; border: 0; cursor: pointer; color: inherit;">
                Lewati
            </button>

            <div style="display: flex; gap: 0.5rem;">
                <button
                    type="button"
                    x-show="step > 0"
                    x-on:click="prev()"
                    style="font-size: 0.875rem; padding: 0.5rem 0.875rem; border-radius: 0.5rem; border: 1px solid rgba(128, 128, 128, 0.4); background: none; cursor: pointer; color: inherit;"
                >
                    Kembali
                </button>
                <button
                    type="button"
                    x-on:click="next()"
                    style="font-size: 0.875rem; padding: 0.5rem 0.875rem; border-radius: 0.5rem; border: 0; background: #ED1651; color: #ffffff; cursor: pointer; font-weight: 600;"
                    x-text="step < steps.length - 1 ? 'Lanjut' : 'Selesai'"
                ></button>
            </div>
        </div>
    </div>
</div>
