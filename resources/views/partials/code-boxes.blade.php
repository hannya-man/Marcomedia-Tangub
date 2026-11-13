{{-- A 6-digit code as six boxes, sent as one field named "code". Typing moves to the next box,
     Backspace to the one before, and a pasted or autofilled code fills them all.
     $auto: send the form by itself once the sixth digit is in. $disabled: an Alpine expression that
     turns the field off (the recovery-code option). $invalid: draw the boxes red. --}}
<div x-data="codeBoxes(@js($auto ?? false))" class="flex justify-center gap-2" @paste.prevent="fill($event.clipboardData.getData('text'))">
    <input type="hidden" name="code" :value="digits.join('')" @isset($disabled) :disabled="{{ $disabled }}" @endisset>
    <template x-for="(digit, i) in digits" :key="i">
        <input type="text" inputmode="numeric" maxlength="6" :aria-label="`Digit ${i + 1} of 6`" :autocomplete="i === 0 ? 'one-time-code' : 'off'"
               :value="digit" @input="type(i, $event.target)" @keydown.backspace="back(i, $event)" @focus="$event.target.select()"
               @keydown.arrow-left.prevent="box(i - 1)?.focus()" @keydown.arrow-right.prevent="box(i + 1)?.focus()"
               @if(! empty($focus)) x-init="if (i === 0) $nextTick(() => $el.focus())" @endif
               class="w-11 h-14 sm:w-12 text-center text-2xl font-semibold rounded-lg border bg-white text-ink focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 {{ ! empty($invalid) ? 'border-red-400' : 'border-slate-300' }}">
    </template>
</div>
@once
<script>
function codeBoxes(auto) {
    return {
        digits: ['', '', '', '', '', ''],
        sent: false,
        box(i) { return this.$root.querySelectorAll('input[type=text]')[i]; },
        type(i, input) {
            const typed = input.value.replace(/\D/g, '');
            if (typed.length > 1) return this.fill(typed, i);   // autofill puts the whole code in one box
            this.digits[i] = typed;
            input.value = typed;
            if (typed) this.box(i + 1)?.focus();
            this.done();
        },
        back(i, event) {
            if (this.digits[i] === '' && i > 0) {
                event.preventDefault();
                this.digits[i - 1] = '';
                this.box(i - 1).focus();
            }
        },
        fill(text, from = 0) {
            text.replace(/\D/g, '').slice(0, 6 - from).split('').forEach((d, k) => this.digits[from + k] = d);
            this.box(Math.min(this.digits.findIndex((d) => d === '') === -1 ? 5 : this.digits.findIndex((d) => d === ''), 5))?.focus();
            this.done();
        },
        done() {
            if (auto && !this.sent && this.digits.every((d) => d !== '')) {
                this.sent = true;
                this.$nextTick(() => this.$root.closest('form').requestSubmit());
            }
        },
    };
}
</script>
@endonce
