<?php

namespace App\Services;


use App\Models\StockProductFieldValue;
use Illuminate\Support\Facades\DB;
use App\Services\GenerateBillNumbers;
use Illuminate\Support\Str;
use App\Models\Voucher;
use App\Models\Stock;
use App\Models\AccountHead;
use App\Models\Party;
use App\Models\Bank;
use Carbon\Carbon;
use Pratiksh\Nepalidate\Services\NepaliDate;
use App\Models\VoucherDetails;

class VoucherReportService
{

    protected $billService;

    public function __construct(GenerateBillNumbers $billService)
    {
        $this->billService = $billService;
    }



    public function generateVoucherReport($stockId, $type, $stockData = null)
    {
        return DB::transaction(function () use ($stockId, $type, $stockData) {

            $entries = [];

            $payment = $stockData['payment'] ?? [];
            $partyId = $stockData['party_id'] ?? null;
            $partyAccountHeadId = AccountHead::where('party_id', $partyId)->value('id') ?? null;
            $roundOffType = $stockData['roundoff_type'] ?? null;
            $roundOffAmount = $stockData['roundoff_amount'] ?? 0;

            if (is_string($payment)) {
                $payment = json_decode($payment, true) ?? [];
            }

            $partyName = Party::where('id', $stockData['party_id'] ?? null)->value('name') ?? 'Customer';
            $bankId = Party::where('id', $stockData['party_id'] ?? null)->value('bank_id') ?? 'Bank';
            $bankName = Bank::where('id', $bankId ?? null)->value('name') ?? 'Bank';
            $companyId = $stockData['company_id'] ?? null;
            $billNumber = $stockData['bill_number'];
            $branchId = $stockData['branch_id'] ?? null;

            $taxable = ($stockData['taxable_amount'] ?? 0);
            $nonTaxable = ($stockData['non_taxable_amount'] ?? 0);

            $vatAmount = ($taxable * 13) / 100;
            $netTotal = $taxable + $nonTaxable;

            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

            $currentBsYear = (int) $currentBsYear;
            $currentBsMonth = (int) $currentBsMonth;


            $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


            $startYear = substr($fiscalYear, -2);
            $endYear = substr($fiscalYear + 1, -2);

            $fiscalYearCode = $startYear . $endYear;

            $type = trim($type);

            if ($type == 'purchase') {
                if ($billNumber) {

                    $exists = Stock::where('bill_number', $billNumber)
                        ->where('type', 'purchase')
                        ->exists();

                    if ($exists) {
                        $billNumber = $this->billService->getPurchaseBillNumber($branchId);
                    }

                } else {
                    $billNumber = $this->billService->getPurchaseBillNumber($branchId);
                }

                $voucherNumber = $billNumber;
            } elseif ($type == 'sales_return') {
                if ($billNumber) {

                    $exists = Stock::where('bill_number', $billNumber)
                        ->where('type', 'sales_return')
                        ->exists();

                    if ($exists) {
                        $billNumber = $this->billService->getSalesReturnBillNumber($branchId);
                    }

                } else {
                    $billNumber = $this->billService->getSalesReturnBillNumber($branchId);
                }

                $voucherNumber = $billNumber;


            } else {
                throw new \Exception("Invalid voucher type: $type");
            }





            $type = trim($type);

            $voucher = Voucher::create([
                'voucher_number' => $voucherNumber,
                'company_id' => $stockData['company_id'],
                'branch_id' => $stockData['branch_id'],
                'voucher_type' => $type,
                'reference_id' => $stockId,
                'reference_type' => 'stock',
                'date' => $stockData['invoice_date'] ?? Carbon::now()->toDateString(),
                'date_bs' => $stockData['invoice_date_bs'] ?? Carbon::now()->toDateString(),
                'description' => 'Stock Purchase Voucher',
                'total_amount' => $netTotal + $vatAmount,
            ]);

            $narration = match ($type) {
                'purchase' => 'Purchase Inventory',
                'sales_return' => 'Sales Return Inventory',
                default => 'Inventory',
            };

            $rounOffAccountHead = null;
            $roundOffNarration = null;

            if ($type == 'purchase' && $roundOffType == "plus") {
                $rounOffAccountHead = 52;
                $roundOffNarration = 'Round Off Plus in Purchase';
            } elseif ($type == 'purchase' && $roundOffType == "minus") {
                $rounOffAccountHead = 15;
                $roundOffNarration = 'Round Off Minus in Purchase';
            } elseif ($type == 'sales_return' && $roundOffType == "plus") {
                $rounOffAccountHead = 14;
                $roundOffNarration = 'Round Off Plus in Sales Return';
            } elseif ($type == 'sales_return' && $roundOffType == "minus") {
                $rounOffAccountHead = 51;
                $roundOffNarration = 'Round Off Minus in Sales Return';
            }



            if ($type == 'purchase') {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => 16,
                    'narration' => $narration,
                    'debit' => $netTotal,
                    'credit' => 0,
                ];

            } elseif ($type == 'sales_return') {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => 13,
                    'narration' => $narration,
                    'debit' => $netTotal,
                    'credit' => 0,
                ];

            }


            if ($vatAmount > 0) {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => 10,
                    'narration' => 'VAT 13%',
                    'debit' => $vatAmount,
                    'credit' => 0,
                ];
            }


            $cash = ($payment['cash'] ?? 0);

            $bank = ($payment['bank'] ?? 0);
            $credit = ($payment['credit'] ?? 0);

            if ($cash > 0) {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => 1,
                    'narration' => 'Cash Payment',
                    'debit' => 0,
                    'credit' => $cash,
                    'payment_mode' => 'cash'
                ];
            }

            if ($bank > 0) {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => 4,
                    'narration' => $bankName ?? 'Bank Payment',
                    'debit' => 0,
                    'credit' => $bank,
                    'payment_mode' => 'bank'
                ];
            }

            if ($credit > 0) {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => $partyAccountHeadId ?? 5,
                    'narration' => $partyName ?? 'Credit Payment',
                    'debit' => 0,
                    'credit' => $credit,
                    'payment_mode' => 'credit'
                ];
            }




            $totalDebit = array_sum(array_column($entries, 'debit'));
            $totalCredit = array_sum(array_column($entries, 'credit'));

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new \Exception("Voucher not balanced: Debit $totalDebit != Credit $totalCredit");
            }

            if ($rounOffAccountHead) {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => $rounOffAccountHead,
                    'narration' => $roundOffNarration,
                    'debit' => 0,
                    'credit' => $roundOffAmount

                ];
            }


            $detailRows = [];

            foreach ($entries as $entry) {
                $detailRows[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => $entry['account_head_id'],
                    'narration' => $entry['narration'] ?? null,
                    'debit' => $entry['debit'] ?? 0,
                    'credit' => $entry['credit'] ?? 0,
                    'tax_amount' => $entry['tax_amount'] ?? 0,
                    'taxable_amount' => $entry['taxable_amount'] ?? 0,
                    "company_id" => $companyId,
                    "branch_id" => $branchId,

                    'payment_mode' => $entry['payment_mode'] ?? null,
                    'payment_account_id' => $entry['payment_account_id'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            VoucherDetails::insert($detailRows);

            return $voucher->load('voucherDetails');
        });
    }


    public function generateOutgoingVoucherReport($stockId, $type, $stockData = null)
    {
        return DB::transaction(function () use ($stockId, $type, $stockData) {

            $entries = [];

            $payment = $stockData['payment'] ?? [];
            if (is_string($payment)) {
                $payment = json_decode($payment, true) ?? [];
            }

            $partyId = $stockData['party_id'] ?? null;
            $partyAccountHeadId = AccountHead::where('party_id', $partyId)->value('id') ?? null;
            $roundOffType = $stockData['roundoff_type'] ?? null;
            $roundOffAmount = $stockData['roundoff_amount'] ?? 0;

            $partyName = Party::where('id', $stockData['party_id'] ?? null)->value('name') ?? 'Customer';
            $bankId = Party::where('id', $stockData['party_id'] ?? null)->value('bank_id') ?? 'Bank';
            $bankName = Bank::where('id', $bankId ?? null)->value('name') ?? 'Bank';

            $companyId = $stockData['company_id'] ?? null;

            $branchId = $stockData['branch_id'] ?? null;

            $taxable = ($stockData['taxable_amount'] ?? 0);

            $nonTaxable = ($stockData['non_taxable_amount'] ?? 0);

            $vatAmount = ($taxable * 13) / 100;

            $netTotal = $taxable + $nonTaxable;

            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

            $currentBsYear = (int) $currentBsYear;
            $currentBsMonth = (int) $currentBsMonth;


            $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


            $startYear = substr($fiscalYear, -2);
            $endYear = substr($fiscalYear + 1, -2);

            $fiscalYearCode = $startYear . $endYear;


            $type = trim($type);

            if ($type == 'purchase_return') {
                $lastVoucher = Voucher::where('voucher_type', 'purchase_return')
                    ->where('voucher_number', 'like', "VPR{$fiscalYearCode}-{$branchId}-%")
                    ->whereNull('deleted_at')
                    ->orderByDesc('id')
                    ->first();

                $lastNumber = $lastVoucher
                    ? (int) substr($lastVoucher->voucher_number, -7)
                    : 0;

                $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

                $voucherNumber = "VPR{$fiscalYearCode}-{$branchId}-{$newNumber}";

            } elseif ($type == 'sale') {
                $lastVoucher = Voucher::where('voucher_type', 'sale')
                    ->where('voucher_number', 'like', "VS{$fiscalYearCode}-{$branchId}-%")
                    ->whereNull('deleted_at')
                    ->orderByDesc('id')
                    ->first();

                $lastNumber = $lastVoucher
                    ? (int) substr($lastVoucher->voucher_number, -7)
                    : 0;

                $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

                $voucherNumber = "VS{$fiscalYearCode}-{$branchId}-{$newNumber}";

            } else {
                throw new \Exception("Invalid voucher type: $type");
            }




            $voucher = Voucher::create([
                'voucher_number' => $voucherNumber,
                'company_id' => $stockData['company_id'],
                'branch_id' => $stockData['branch_id'],
                'voucher_type' => $type,
                'reference_id' => $stockId,
                'reference_type' => 'stock',
                'date' => $stockData['invoice_date'] ?? Carbon::now()->toDateString(),
                'date_bs' => $stockData['invoice_date_bs'] ?? Carbon::now()->toDateString(),
                'description' => 'Stock Purchase Voucher',
                'total_amount' => $netTotal + $vatAmount,
            ]);



            $narration = match ($type) {
                'purchase_return' => 'Purchase Return Inventory',
                'sale' => 'Sales Inventory',
                default => 'Inventory',
            };

            $rounOffAccountHead = null;
            $roundOffNarration = null;

            if ($type == 'purchase_return' && $roundOffType == "plus") {
                $rounOffAccountHead = 52;
                $roundOffNarration = 'Round Off Plus in Purchase';
            } elseif ($type == 'purchase_return' && $roundOffType == "minus") {
                $rounOffAccountHead = 15;
                $roundOffNarration = 'Round Off Minus in Purchase';
            } elseif ($type == 'sale' && $roundOffType == "plus") {
                $rounOffAccountHead = 14;
                $roundOffNarration = 'Round Off Plus in Sales';
            } elseif ($type == 'sale' && $roundOffType == "minus") {
                $rounOffAccountHead = 51;
                $roundOffNarration = 'Round Off Minus in Sales';
            }

            if ($type == 'purchase_return') {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => 17,
                    'narration' => $narration,
                    'debit' => 0,
                    'credit' => $netTotal,
                ];

            } elseif ($type == 'sale') {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => 12,
                    'narration' => $narration,
                    'debit' => 0,
                    'credit' => $netTotal,
                ];
            }


            if ($vatAmount > 0) {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => 10,
                    'narration' => 'VAT 13%',
                    'debit' => 0,
                    'credit' => $vatAmount,
                ];
            }


            $cash = ($payment['cash'] ?? 0);

            $bank = ($payment['bank'] ?? 0);

            $credit = ($payment['credit'] ?? 0);

            if ($cash > 0) {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => 1,
                    'narration' => 'Cash Payment',
                    'debit' => $cash,
                    'credit' => 0,
                    'payment_mode' => 'cash'
                ];
            }

            if ($bank > 0) {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => 4,
                    'narration' => $bankName ?? 'Bank Payment',
                    'debit' => $bank,
                    'credit' => 0,
                    'payment_mode' => 'bank'
                ];
            }

            if ($credit > 0) {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => $partyAccountHeadId ?? 5,
                    'narration' => $partyName ?? 'Credit Payment',
                    'debit' => $credit,
                    'credit' => 0,
                    'payment_mode' => 'credit'
                ];
            }


            $totalDebit = array_sum(array_column($entries, 'debit'));
            $totalCredit = array_sum(array_column($entries, 'credit'));

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new \Exception("Voucher not balanced: Debit $totalDebit != Credit $totalCredit");
            }

            if ($rounOffAccountHead) {
                $entries[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => $rounOffAccountHead,
                    'narration' => $roundOffNarration,
                    'debit' => $roundOffAmount,
                    'credit' => 0

                ];
            }


            $detailRows = [];

            foreach ($entries as $entry) {
                $detailRows[] = [
                    'voucher_id' => $voucher->id,
                    'account_head_id' => $entry['account_head_id'],
                    'narration' => $entry['narration'] ?? null,
                    'debit' => $entry['debit'] ?? 0,
                    'credit' => $entry['credit'] ?? 0,
                    'tax_amount' => $entry['tax_amount'] ?? 0,
                    'taxable_amount' => $entry['taxable_amount'] ?? 0,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'payment_mode' => $entry['payment_mode'] ?? null,
                    'payment_account_id' => $entry['payment_account_id'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            VoucherDetails::insert($detailRows);

            return $voucher->load('voucherDetails');
        });
    }


}
?>