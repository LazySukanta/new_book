<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class WorkspaceAuditController
{
    public function show(Request $request, ?int $audit = null): View
    {
        $branch = $this->branch($request);
        foreach (['action', 'entity_type', 'entity_id', 'user_id', 'from', 'to'] as $field) {
            if (! $request->filled($field)) {
                $request->query->remove($field);
            }
        }
        $record = $audit ? app(AuditController::class)->show($request, $audit)->getData(true)['data'] : null;
        $page = $audit ? [] : app(AuditController::class)->index($request)->getData(true);
        $scope = DB::table('audit_logs')->where('tenant_id', $branch->tenant_id)
            ->where(fn ($query) => $query->where('branch_id', $branch->id)->orWhereNull('branch_id'));
        return view('audit-workspace', [
            'branch' => $branch, 'branches' => $this->allowed($request)->orderBy('name')->get(), 'record' => $record, 'page' => $page,
            'actions' => (clone $scope)->distinct()->orderBy('action')->limit(100)->pluck('action'),
            'types' => (clone $scope)->distinct()->orderBy('entity_type')->limit(100)->pluck('entity_type'),
            'users' => DB::table('users')->where('tenant_id', $branch->tenant_id)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function download(Request $request, Excel $excel): BinaryFileResponse
    {
        $this->branch($request);
        foreach (['action', 'entity_type', 'entity_id', 'user_id', 'from', 'to'] as $field) {
            if (! $request->filled($field)) {
                $request->query->remove($field);
            }
        }
        return app(ReportExportController::class)->audit($request, $excel);
    }

    private function allowed(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return $request->user()->accessibleBranches()->whereIn('branches.id', $request->attributes->get('device')->branches()->select('branches.id'));
    }

    private function branch(Request $request): Branch
    {
        abort_unless($request->user()->hasRole('Owner') && $request->user()->can('audit.view'), 403);
        if ($request->filled('branch_id')) {
            $request->validate(['branch_id' => 'integer|min:1']);
            $branch = $this->allowed($request)->findOrFail($request->input('branch_id'));
            $request->session()->put('workspace.branch', $branch->id);
        } else {
            $branch = $this->allowed($request)->find(session('workspace.branch')) ?? $this->allowed($request)->orderBy('id')->firstOrFail();
        }
        $request->attributes->set('branch', $branch);
        return $branch;
    }
}
