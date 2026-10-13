<?php
namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Services\Catalog\ProductEditor;
use App\Services\Sales\SaleVoider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What cashiers ask owners to approve: adding a product, changing one, or voiding a sale. Owners
 * see what's waiting and approve or reject it (with a note); cashiers see their own requests and
 * the answers. Approving runs the same checks and saving as an owner's own change.
 */
class ApprovalController extends Controller
{
    public function __construct(private ProductEditor $products, private SaleVoider $voider)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $owner = $user->isAdmin();
        $mine = fn ($q) => $owner ? $q : $q->where('requested_by', $user->id);

        $pending = ApprovalRequest::with(['requester', 'product.category', 'sale.items', 'sale.customer', 'sale.user'])
            ->pending()->where($mine)->oldest()->get()
            ->map(fn ($r) => ['request' => $r] + $this->details($r));
        $decided = ApprovalRequest::with(['requester', 'decider'])
            ->where('status', '!=', 'pending')->where($mine)->latest('decided_at')->limit(40)->get();

        // A cashier has now seen the owner's answers (the menu stops counting them as new).
        if (! $owner) {
            $unseen = $decided->whereNull('requester_seen_at')->pluck('id');
            if ($unseen->isNotEmpty()) {
                ApprovalRequest::whereIn('id', $unseen)->update(['requester_seen_at' => now()]);
            }
        }

        return view('approvals.index', compact('owner', 'pending', 'decided'));
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest)
    {
        if (! $approvalRequest->isPending()) {
            return back()->with('error', 'That request was already answered.');
        }

        try {
            $message = DB::transaction(fn () => $this->apply($approvalRequest, $request));
        } catch (ValidationException $e) {
            $why = collect($e->errors())->flatten()->unique()->implode(' ');

            return back()->with('error', "\"{$approvalRequest->summary}\" can't be approved as it was sent: {$why} Reject it with a note, or make the change yourself.");
        }

        return redirect()->route('approvals.index')->with('success', $message);
    }

    public function reject(Request $request, ApprovalRequest $approvalRequest)
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);
        if (! $approvalRequest->isPending()) {
            return back()->with('error', 'That request was already answered.');
        }

        $approvalRequest->decide('rejected', $request->user(), $data['note'] ?? null);

        return redirect()->route('approvals.index')->with('success', "Rejected: {$approvalRequest->summary}. {$approvalRequest->requester->name} will see it in Approvals.");
    }

    // A cashier takes back their own request, before an owner answers it.
    public function cancel(Request $request, ApprovalRequest $approvalRequest)
    {
        if ((int) $approvalRequest->requested_by !== (int) $request->user()->id || ! $approvalRequest->isPending()) {
            return back()->with('error', "That request can't be cancelled.");
        }

        $approvalRequest->forceFill(['status' => 'cancelled', 'decided_at' => now(), 'requester_seen_at' => now()])->save();

        return back()->with('success', "Cancelled: {$approvalRequest->summary}.");
    }

    // Does what was asked, as an owner would, and closes the request. Returns the message.
    // A ValidationException (the catalog changed since it was sent) rolls it all back.
    private function apply(ApprovalRequest $approval, Request $request): string
    {
        $owner = $request->user();
        // Two owners pressing Approve at once: the second finds it answered.
        if (ApprovalRequest::whereKey($approval->id)->lockForUpdate()->value('status') !== 'pending') {
            throw ValidationException::withMessages(['request' => 'It was already answered.']);
        }

        switch ($approval->type) {
            case 'product.create':
                $input = $this->products->normalizeNew($approval->payload ?? []);
                $product = $this->products->create($this->products->validateNew($input));
                $approval->forceFill(['product_id' => $product->id])->save();
                $approval->decide('approved', $owner);

                return "Added '{$product->name}' to Products, as {$approval->requester->name} asked.";

            case 'product.update':
                $product = $approval->product ?? throw ValidationException::withMessages(['product' => 'The product no longer exists.']);
                $input = $this->products->normalizeChanges($approval->payload ?? []);
                $result = $this->products->update($product, $this->products->validateChanges($product, $input));
                $approval->decide('approved', $owner);

                return "Saved {$approval->requester->name}'s changes to {$product->name}."
                    . ($result['hidden'] ? ' Hidden instead of removed, since they have sales: ' . implode(', ', $result['hidden']) . '.' : '')
                    . ($result['count_started'] ? ' Its stock count starts at 0: add what you have with the + in Restock.' : '');

            case 'sale.void':
                $sale = $approval->sale;
                if (! $sale || ! $this->voider->canVoid($sale)) {
                    throw ValidationException::withMessages(['sale' => 'The sale is ' . ($sale->status ?? 'gone') . ' now, and only pending or processing sales can be voided.']);
                }
                $filled = $this->voider->void($sale, $owner);   // closes this request as approved

                return "Voided {$sale->invoice_number}, as {$approval->requester->name} asked. Its stock was restored." . $filled;
        }

        throw ValidationException::withMessages(['type' => 'Unknown kind of request.']);
    }

    // What the request would do, in plain words, and whether it can still be done.
    private function details(ApprovalRequest $r): array
    {
        switch ($r->type) {
            case 'product.create':
                return ['product' => $this->products->describeNew($this->products->normalizeNew($r->payload ?? [])), 'blocked' => null];

            case 'product.update':
                if (! $r->product) {
                    return ['changes' => [], 'blocked' => 'The product no longer exists.'];
                }

                return [
                    'changes' => $this->products->describeChanges($r->product, $this->products->normalizeChanges($r->payload ?? [])),
                    'blocked' => null,
                    'note' => $r->product->status === 'archived' ? 'This product is in the Archive now. Approving still saves the changes.' : null,
                ];

            case 'sale.void':
                return ['blocked' => $r->sale && $this->voider->canVoid($r->sale) ? null
                    : 'This sale is ' . ($r->sale->status ?? 'gone') . ' now, so it can no longer be voided. Reject this request.'];
        }

        return ['blocked' => 'Unknown kind of request.'];
    }
}
