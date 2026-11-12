<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer screen - Marcomedia POS</title>
    <link rel="icon" href="/icons/icon-192.png">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
{{-- For a second monitor facing the customer. Billing/POS, open in this same browser, sends the order
     as it's rung up (a BroadcastChannel); this page only shows it. Nothing here can be clicked. --}}
<body class="auth-backdrop min-h-screen text-white select-none">
    <main class="min-h-screen flex flex-col p-8 lg:p-12">
        <img src="{{ asset('images/marcomedia-logo-transparent.png') }}" alt="Marcomedia" class="h-16 w-auto self-start">

        <section id="welcome" class="flex-1 flex flex-col items-center justify-center text-center">
            <p class="text-4xl lg:text-5xl font-semibold">Welcome to Marcomedia</p>
            <p class="mt-3 text-lg text-slate-300">The best place to get customized</p>
        </section>

        <section id="order" class="hidden flex-1 flex flex-col mt-8 min-h-0">
            <p id="count" class="text-sm uppercase tracking-widest text-slate-400"></p>
            <ul id="lines" class="mt-3 flex-1 overflow-y-auto divide-y divide-white/10 text-xl"></ul>
            <div class="mt-6 border-t border-white/20 pt-6 space-y-2">
                <p id="discount" class="hidden flex justify-between text-lg text-slate-300"><span>Discount</span><span></span></p>
                <p class="flex justify-between items-baseline"><span class="text-2xl">Total</span><span id="total" class="text-5xl font-semibold"></span></p>
                <p id="pay" class="hidden flex justify-between text-xl text-slate-300"><span></span><span></span></p>
                <p id="after" class="hidden flex justify-between text-2xl font-semibold text-amber-300"><span></span><span></span></p>
            </div>
        </section>

        <section id="thanks" class="hidden flex-1 flex flex-col items-center justify-center text-center">
            <p class="text-5xl font-semibold">Thank you!</p>
            <p id="thanks-note" class="mt-4 text-2xl text-slate-300"></p>
        </section>
    </main>

<script>
(function () {
    const $ = (id) => document.getElementById(id);
    const peso = (n) => '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const methods = { cash: 'Cash', gcash: 'GCash' };
    let thanksUntil = 0;

    function show(id) {
        for (const section of ['welcome', 'order', 'thanks']) $(section).classList.toggle('hidden', section !== id);
    }
    function row(id, label, value) {
        $(id).classList.toggle('hidden', value === null);
        if (value !== null) [$(id).children[0].textContent, $(id).children[1].textContent] = [label, value];
    }

    function drawOrder(order) {
        if (!order.lines.length) {
            if (Date.now() > thanksUntil) show('welcome');   // the thank-you stays up a while after a sale
            return;
        }
        thanksUntil = 0;
        $('count').textContent = order.lines.length + (order.lines.length === 1 ? ' item' : ' items');
        // Built as text, never as HTML: an item's name or details can't change the page.
        $('lines').replaceChildren(...order.lines.map((line) => {
            const li = document.createElement('li');
            li.className = 'py-3 flex justify-between gap-6';
            const name = document.createElement('span');
            name.textContent = line.name;
            const sub = document.createElement('span');
            sub.className = 'block text-base text-slate-400';
            sub.textContent = line.quantity + ' × ' + peso(line.price);
            name.append(sub);
            const total = document.createElement('span');
            total.className = 'whitespace-nowrap';
            total.textContent = peso(line.total);
            li.append(name, total);
            return li;
        }));
        $('lines').lastElementChild?.scrollIntoView({ block: 'nearest' });
        $('total').textContent = peso(order.total);
        row('discount', 'Discount', order.discount > 0 ? '−' + peso(order.discount) : null);

        // How it's being paid, once the cashier types it: the change, or what's left after a down payment.
        const paid = order.paid > 0 ? order.paid : null;
        row('pay', methods[order.method] || 'Paid', paid === null ? null : peso(paid));
        if (paid === null) row('after', '', null);
        else if (paid < order.total) row('after', 'Balance to pay later', peso(order.total - paid));
        else row('after', 'Change', order.method === 'cash' && paid > order.total ? peso(paid - order.total) : null);
        show('order');
    }

    function drawThanks(sale) {
        const change = sale.method === 'cash' ? sale.paid - sale.total : 0;
        $('thanks-note').textContent = sale.paid < sale.total
            ? 'Down payment received. Balance: ' + peso(sale.total - sale.paid)
            : change > 0 ? 'Your change: ' + peso(change) : peso(sale.total) + ' · ' + (methods[sale.method] || '');
        thanksUntil = Date.now() + 8000;
        show('thanks');
        setTimeout(() => { if (Date.now() >= thanksUntil && thanksUntil) { thanksUntil = 0; show('welcome'); } }, 8100);
    }

    if (!('BroadcastChannel' in window)) {
        $('welcome').querySelector('p + p').textContent = 'This browser can\'t show the order here. Use Chrome or Edge.';
        return;
    }
    const channel = new BroadcastChannel('marcomedia-customer-screen');
    channel.onmessage = (e) => {
        if (e.data?.type === 'order') drawOrder(e.data.order);
        if (e.data?.type === 'done') drawThanks(e.data);
    };
    channel.postMessage({ type: 'hello' });   // Billing/POS answers with the order on it now
})();
</script>
</body>
</html>
