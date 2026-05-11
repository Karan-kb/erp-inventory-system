<?php
namespace App\Services;

use Exception;
use Pratiksh\Nepalidate\Services\NepaliDate;
use App\Models\StockProductFieldValue;
use App\Models\Voucher;
use App\Models\Product;
use Carbon\Carbon;

use App\Models\Stock;

class GenerateBillNumbers
{


    public function getProductID($companyId)
    {
        if (!$companyId) {
            throw new Exception("Please provide company name !!");
        }
        $latestProduct = Product::withTrashed()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->first();



        if ($latestProduct && preg_match('/PID-(\d+)/', $latestProduct->product_unique_id, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        } else {
            $nextNumber = 1;
        }



        $productID = 'PID-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);


        while (
            Product::withoutTrashed()
                ->where('company_id', $companyId)
                ->where('product_code', $productID)
                ->exists()
        ) {
            $nextNumber++;
            $productID = 'PID-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
        }

        return $productID;
    }



    public function getOpeningStockBillNumbers($branchId)
    {
        if (!$branchId) {
            throw new Exception("Branch ID is required.");
        }

        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = substr($fiscalYear, -2);
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . $endYear;



        $lastStock = Stock::where('type', 'opening_stock')
            ->where('bill_number', 'like', "OS{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "OS{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;


    }




    public function getPurchaseBillNumber($branchId)
    {

        if (!$branchId) {
            throw new Exception("Branch Id is required.");
        }
        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = $fiscalYear;
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . '/' . $endYear;




        $lastStock = Stock::where('type', 'purchase')
            ->where('bill_number', 'like', "P{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "P{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;

    }


    public function getStockAdjustmentBillNumber($branchId)
    {

        if (!$branchId) {
            throw new Exception("Branch Id is required.");
        }
        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = $fiscalYear;
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . '/' . $endYear;




        $lastStock = Stock::where('type', 'stock_adjustment')
            ->where('bill_number', 'like', "ADJ{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "ADJ{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;

    }


    public function getStockTransferBillNumber($branchId)
    {

        if (!$branchId) {
            throw new Exception("Branch Id is required.");
        }
        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = $fiscalYear;
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . '/' . $endYear;




        $lastStock = Stock::where('type', 'stock_transfer')
            ->where('bill_number', 'like', "TRS{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "TRS{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;

    }

    public function getVoucherNumber($branchId, $receiptVoucher)
    {
        if (!$branchId) {
            throw new Exception("Branch Id is required.");
        }

        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;

        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;

        $startYear = substr($fiscalYear, -2);
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . $endYear;

        $type = $receiptVoucher;


        if ($type == "journal_voucher") {
            $prefix = "JV";
        } elseif ($type == "receipt_voucher") {
            $prefix = "RV";
        } elseif ($type == "payment_voucher") {
            $prefix = "PV";
        } else {
            throw new Exception("Invalid voucher type !!");
        }


        $lastVoucher = Voucher::where('voucher_type', $type)
            ->where('voucher_number', 'like', "{$prefix}{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastVoucher
            ? (int) substr($lastVoucher->voucher_number, -7)
            : 0;

        do {

            $lastNumber++;

            $newNumber = str_pad($lastNumber, 7, '0', STR_PAD_LEFT);

            $voucherNumber = "{$prefix}{$fiscalYearCode}-{$branchId}-{$newNumber}";

            $exists = Voucher::where('voucher_number', $voucherNumber)
                ->whereNull('deleted_at')
                ->exists();

        } while ($exists);

        return $voucherNumber;
    }

    function getPurchaseReturnBillNumber($branchId)
    {

        if (!$branchId) {
            throw new Exception("Branch Id is required.");
        }
        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = $fiscalYear;
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . '/' . $endYear;




        $lastStock = Stock::where('type', 'purchase_return')
            ->where('bill_number', 'like', "PR{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "PR{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;

    }


    function getSalesBillNumber($branchId)
    {
        if (!$branchId) {
            throw new Exception("Branch Id is required.");
        }
        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = $fiscalYear;
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . '/' . $endYear;




        $lastStock = Stock::where('type', 'sale')
            ->where('bill_number', 'like', "S{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "S{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;

    }


    function getSalesReturnBillNumber($branchId)
    {
        if (!$branchId) {
            throw new Exception("Branch Id is required.");
        }
        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = $fiscalYear;
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . '/' . $endYear;





        $lastStock = Stock::where('type', 'sales_return')
            ->where('bill_number', 'like', "S{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "SR{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;

    }
}

?>