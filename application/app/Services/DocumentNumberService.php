<?php

namespace App\Services;

use App\Http\ApiResponse;
use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use Closure;
use DateTimeInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DocumentNumberService
{
    public const SOURCES = [
        'tax_invoice' => 'sale', 'bill_of_supply' => 'sale', 'commercial_bill' => 'sale',
        'credit_note' => 'sale_return', 'purchase_return' => 'purchase_return', 'stock_transfer' => 'stock_transfer',
    ];

    /** The callback writes the backing document and returns its ID inside the posting transaction. */
    public function issue(User $actor, Branch $branch, Device $device, string $type, DateTimeInterface $issuedAt, Closure $write): object
    {
        if (DB::transactionLevel() === 0 || ! isset(self::SOURCES[$type])) {
            throw new LogicException('Number allocation requires a supported backing document transaction.');
        }
        abort_unless($branch->tenant_id === $actor->tenant_id && $device->tenant_id === $actor->tenant_id && $device->status === 'active'
            && $device->branches()->whereKey($branch->id)->exists(), 403);
        $branch = $actor->accessibleBranches()->lockForUpdate()->findOrFail($branch->id);
        abort_unless(preg_match('/\A[A-Z0-9]{1,3}\z/', $branch->code), 422, 'The branch needs a valid document code.');
        $year = ShopCalendar::financialYear($issuedAt);
        $scope = DB::table('document_series')->where([
            'tenant_id' => $actor->tenant_id, 'branch_id' => $branch->id, 'financial_year' => $year,
            'document_type' => $type, 'owning_channel' => $device->channel,
        ]);
        $series = (clone $scope)->whereIn('status', ['ready', 'active'])->where('next_number', '<=', 99999)
            ->orderBy('rollover_order')->lockForUpdate()->first();
        if (! $series) {
            $configured = (clone $scope)->whereIn('status', ['ready', 'active', 'exhausted', 'closed'])->exists();
            throw new HttpResponseException(ApiResponse::conflict(
                $configured ? 'DOCUMENT_SERIES_EXHAUSTED' : 'DOCUMENT_SERIES_UNAVAILABLE',
                'Provision an available series for this branch, year, document type and device channel.'
            ));
        }
        $serial = $series->next_number;
        $number = sprintf('%s/%s/%s%05d', $branch->code, $year, $series->prefix, $serial);
        $source = $write($number);
        if (! is_int($source) || $source < 1) {
            throw new LogicException('The backing document must be saved before registering its number.');
        }
        $row = [
            'document_series_id' => $series->id, 'device_id' => $device->id, 'created_by' => $actor->id,
            'document_no' => $number, 'serial' => $serial, 'source_type' => self::SOURCES[$type], 'source_id' => $source,
            'issued_at' => $issuedAt,
        ];
        $id = DB::table('issued_documents')->insertGetId($row);
        DB::table('document_series')->where('id', $series->id)->update([
            'next_number' => $serial + 1, 'status' => $serial === 99999 ? 'exhausted' : 'active',
            'first_issued_at' => $series->first_issued_at ?? $issuedAt, 'exhausted_at' => $serial === 99999 ? $issuedAt : null,
        ]);

        return (object) ($row + ['id' => $id]);
    }
}
