<?php

namespace App\Livewire;

use App\Http\Middleware\RequireDevice;
use App\Services\OperationalReports;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class Workspace extends Component
{
    use WithPagination;

    #[Locked]
    public string $section;

    #[Locked]
    public ?int $branchId = null;

    public string $branchSelection = '';

    public function mount(string $section): void
    {
        $this->section = $section;
        $branches = $this->allowedBranches();
        $this->branchId = (clone $branches)->whereKey(session('workspace.branch'))->value('branches.id')
            ?? $branches->orderBy('branches.name')->orderBy('branches.id')->value('branches.id');
        $this->branchSelection = (string) $this->branchId;
    }

    public function selectBranch(): void
    {
        $input = $this->validate(['branchSelection' => 'required|integer|min:1'], attributes: ['branchSelection' => 'branch']);
        $branch = $this->allowedBranches()->find($input['branchSelection']);
        if (! $branch) {
            $this->branchSelection = (string) $this->branchId;

            throw ValidationException::withMessages(['branchSelection' => 'Choose a branch available on this device.']);
        }

        $this->branchId = $branch->id;
        $this->branchSelection = (string) $branch->id;
        session(['workspace.branch' => $branch->id]);
        $this->resetPage();
        $this->dispatch('workspace-branch-changed');
    }

    public function render(): View
    {
        $actor = request()->user();
        $branches = $this->allowedBranches()->orderBy('branches.name')->orderBy('branches.id')->get();
        $branch = $branches->firstWhere('id', $this->branchId);
        abort_if($this->branchId !== null && $branch === null, 403);

        $canSeeStock = $actor->can('stock.view');
        abort_if($this->section === 'stock' && ! $canSeeStock, 403);
        abort_if($this->section === 'pos' && ! $actor->can('sales.create'), 403);
        abort_if($this->section === 'customers' && ! $actor->can('customers.view'), 403);
        abort_if($this->section === 'suppliers' && ! $actor->can('suppliers.manage'), 403);
        abort_if($this->section === 'reports' && ! $actor->canAny(['reports.view', 'reports.daily_own']), 403);

        $today = CarbonImmutable::now('Asia/Kolkata')->startOfDay();
        $reports = app(OperationalReports::class);
        $metrics = [];
        $stock = null;
        if ($branch && $canSeeStock && in_array($this->section, ['overview', 'stock'], true)) {
            $query = $reports->stock($branch)->orderBy('products.name')->orderBy('products.id');
            if (! $actor->can('reports.view')) {
                $query->select('products.id as product_id', 'products.name', 'products.sku', 'products.reorder_level')
                    ->selectRaw('COALESCE(balance.qty, 0.000) AS qty');
            }
            $stock = $this->section === 'stock' ? $query->paginate(15) : $query->limit(5)->get();
        }
        if ($branch && $this->section === 'overview') {
            if ($actor->can('reports.view')) {
                $sales = $reports->sales($branch, $today->utc(), $today->addDay()->utc(), null);
                $metrics[] = ['label' => 'Net sales today', 'icon' => 'receipt', 'money' => $sales->sum('total')];
            }
            if ($canSeeStock) {
                $metrics[] = ['label' => 'Products in stock', 'icon' => 'box', 'count' => $reports->stock($branch)->where('balance.qty', '>', 0)->count()];
                $metrics[] = ['label' => 'Low stock products', 'icon' => 'alert', 'count' => $reports->lowStock($branch)->count()];
            }
        }

        return view('livewire.workspace', [
            'actor' => $actor, 'branches' => $branches, 'branch' => $branch, 'canSeeStock' => $canSeeStock,
            'canSeeValue' => $actor->can('reports.view'), 'date' => $today->format('j M Y'), 'metrics' => $metrics, 'stock' => $stock,
            'states' => in_array($this->section, ['customers', 'suppliers'], true)
                ? \Illuminate\Support\Facades\DB::table('gst_states')->orderBy('name')->pluck('name', 'code') : [],
        ]);
    }

    private function allowedBranches(): Builder
    {
        $request = request();
        $device = app(RequireDevice::class)->resolve($request);

        return $request->user()->accessibleBranches()->whereIn('branches.id', $device->branches()->select('branches.id'));
    }
}
