<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Middleware\ResolveBranch;
use App\Models\Product;
use App\Services\PostingTransaction;
use App\Services\SalePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class WorkspacePosController
{
    public function calculate(Request $request, SalePricing $pricing): JsonResponse
    {
        return DB::transaction(function () use ($request, $pricing) {
            $branch = $request->attributes->get('branch');
            $preview = $pricing->calculate($request->user(), $branch, $request->all());
            $ids = array_column($preview['items'], 'product_id');
            $products = Product::where('tenant_id', $branch->tenant_id)->whereIn('id', $ids)->pluck('name', 'id');
            $stock = DB::table('stock_balances')->where('branch_id', $branch->id)->whereIn('product_id', $ids)->pluck('qty', 'product_id');

            return ApiResponse::data($preview + ['names' => $products, 'stock' => $stock]);
        });
    }

    public function hold(Request $request, SaleController $sales, PostingTransaction $transaction): JsonResponse
    {
        $request->merge(['status' => 'held']);

        return $sales->store($request, $transaction, verifyHeldPricing: true);
    }

    public function checkout(Request $request, SaleController $sales, PostingTransaction $transaction): JsonResponse
    {
        $request->merge(['status' => 'completed']);

        return $sales->store($request, $transaction);
    }

    public function invoices(Request $request, int $branch, ?int $sale = null): Response
    {
        $request->headers->set('X-Branch-Id', (string) $branch);

        return app(ResolveBranch::class)->handle($request, function (Request $request) use ($sale) {
            $actor = $request->user();
            abort_unless($actor->can('sales.create') || $actor->can('reports.view'), 403);
            $branch = $request->attributes->get('branch');
            if ($sale !== null) {
                $invoice = app(SaleController::class)->invoice($request, $sale)->getData(true)['data'];
                $returns = DB::table('sale_returns')->where('sale_id', $sale)
                    ->select('id', 'credit_note_no', 'status')->latest('id')->paginate(15);

                $comments = $actor->hasRole('Owner') ? app(SaleCorrectionController::class)->commentHistory($request, $sale)->getData(true)['data'] : [];

                return $this->renderInvoice($request, $invoice, compact('returns', 'comments'));
            }

            $invoices = DB::table('sales')->where(['tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id])
                ->whereNotNull('issued_document_id')->when(! $actor->can('reports.view'), fn ($query) => $query->where('cashier_id', $actor->id))
                ->select('id', 'invoice_no', 'status', 'issued_at')->latest('id')->paginate(20);

            return response()->view('workspace-invoice-history', compact('invoices', 'branch'));
        });
    }

    public function creditNote(Request $request, int $branch, int $return): Response
    {
        $request->headers->set('X-Branch-Id', (string) $branch);

        return app(ResolveBranch::class)->handle($request, function (Request $request) use ($return) {
            $invoice = app(SaleReturnController::class)->invoice($request, $return)->getData(true)['data'];
            return $this->renderInvoice($request, $invoice);
        });
    }

    private function renderInvoice(Request $request, array $invoice, array $extra = []): Response
    {
        $branch = $request->attributes->get('branch');
        $width = DB::table('shop_settings')->where('tenant_id', $branch->tenant_id)->value('receipt_width_mm');
        $invoice['display_date'] = \Illuminate\Support\Carbon::parse($invoice['date'])->timezone('Asia/Kolkata')->format('d M Y, H:i');
        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter('en_IN', \NumberFormatter::SPELLOUT);
            [$rupees, $paise] = explode('.', $invoice['totals']['total']);
            $invoice['total_in_words'] = ucfirst($formatter->format((int) $rupees)).' rupees'
                .((int) $paise ? ' and '.$formatter->format((int) $paise).' paise' : '').' only';
        }

        return response()->view('workspace-invoice', $extra + ['invoice' => $invoice, 'branch' => $branch, 'width' => (int) $width === 58 ? 58 : 80]);
    }
}
