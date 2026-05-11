<?php

namespace App\Repositories;
use App\Services\VoucherReportService;
use Illuminate\Support\Facades\DB;
use App\Models\Stock;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\Party;
use App\Models\StockProduct;
use App\Models\StockTransaction;
use App\Services\TransactionImplementService;

use App\Models\TransactionPivot;

use App\Models\Vat;
use App\Models\StockMovement;
use App\Models\FiscalYear;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\UnitConversionService;
use App\Services\CurrencyFormatService;
use App\Services\QuantityAllocationService;
use App\Models\StockProductFieldValue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

use App\Interfaces\StockPurchaseReturnRepositoryInterface;

class StockPurchaseReturnRepository implements StockPurchaseReturnRepositoryInterface
{

    protected $unitConversionService;
    protected $quantityAllocationService;
    protected $currencyFormatService;
    protected $taxImplementService;

    protected $voucherReportService;

    public function __construct(UnitConversionService $unitConversionService, QuantityAllocationService $quantityAllocationService, CurrencyFormatService $currencyFormatService, TransactionImplementService $taxImplementService, VoucherReportService $voucherReportService)
    {
        $this->unitConversionService = $unitConversionService;
        $this->quantityAllocationService = $quantityAllocationService;
        $this->currencyFormatService = $currencyFormatService;
        $this->taxImplementService = $taxImplementService;
        $this->voucherReportService = $voucherReportService;

    }

    public function getAllBills()
    {
        return Stock::where('type', 'purchase')
            ->whereNull('deleted_at')
            ->pluck('bill_number')
            ->toArray();
    }




    public function create(array $data)
    {

        DB::beginTransaction();

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $purchaseBillNumber = $data['purchase_bill_number'];


        $purchaseStock = Stock::where('bill_number', $purchaseBillNumber)
            ->where('type', 'purchase')
            ->whereNull('deleted_at')
            ->first();

        if (!$purchaseStock) {
            throw new Exception('Purchase bill not found !!');
        }


        //@todo: use the currency formatters to handle the amount fields in the payload

        //@todo: generate bill numbers for this and it should be unique for the company and bill type

        $appliedVat = Vat::where('is_active', 1)->pluck('vat_percent')->first() ?? 0;

        $branchId = $data['branch_id'];

        $stockData = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'purchase_return',
            'bill_number' => $data['bill_number'] ?? null,
            'purchase_bill_number' => $data['purchase_bill_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'pan_number' => $data['pan_number'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'balance' => $this->currencyFormatService->cleanCurrency($data['amount'] ?? 0) ?? 0,
            'ref_bill_number' => $data['ref_bill_number'] ?? null,
            'return_bill_no' => $data['return_bill_no'] ?? null,
            'reasons' => $data['reasons'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $this->currencyFormatService->cleanCurrency($data['discount_value'] ?? 0) ?? 0,
            'discount_after_vat' => $this->currencyFormatService->cleanCurrency($data['discount_after_vat'] ?? 0) ?? 0,
            'sub_total_before_discount' => $this->currencyFormatService->cleanCurrency($data['sub_total_before_discount'] ?? 0) ?? 0,
            'taxable_amount' => $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0,
            'non_taxable_amount' => $this->currencyFormatService->cleanCurrency($data['non_taxable_amount'] ?? 0) ?? 0,
            'excise_duty' => $this->currencyFormatService->cleanCurrency($data['excise_duty'] ?? 0) ?? 0,
            'vat_percent' => $this->currencyFormatService->cleanCurrency($data['vat_percent'] ?? 0) ?? 0,
            'health_insurance' => $this->currencyFormatService->cleanCurrency($data['health_insurance'] ?? 0) ?? 0,
            'freight_amount' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $this->currencyFormatService->cleanCurrency($data['roundoff_amount'] ?? 0) ?? 0,

            'total_amount' => $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0,
            'payment' => isset($data['payment']) ? json_encode($data['payment']) : null,
            'remarks' => $data['remarks'] ?? null,
        ];

        $stock = Stock::create($stockData);

        $panNumber = $data['pan_number'] ?? null;

        if ($panNumber) {

            $panExists = Party::where('pan_number', $data['pan_number'])->where('id', '!=', $data['party_id'])->exists();

            if ($panExists) {

                throw new \Exception('Pan Number already exists.');

            }

            Party::where('id', $data['party_id'])->update([
                'pan_number' => $data['pan_number']
            ]);
        }

        $usedQtyTracker = [];




        foreach ($data['stock_transactions'] as $product) {
            $trackerId = (string) Str::uuid();

            //@todo: handle case when field values are not present
            if (empty($product['field_values'])) {




                $basequantity = $this->unitConversionService->convertToBaseUnit(
                    $product['measure_unit_id'],
                    $product['quantity']
                );
                $alreadyUsed = $usedQtyTracker[$product['product_id']] ?? 0;

                $remainingQty = $basequantity - $alreadyUsed;



                //@todo: allocate the quantity correctly based on the avaialble purhcased products for this bill

                //@todo: also keep track of alreay used quanitity inside the payload to avoid reusing same quantity for multiple return products

                $allocatedQtys = $this->quantityAllocationService->allocateBillWiseQuantity(
                    $purchaseStock->id,
                    $product['product_id'],
                    $branchId,
                    $basequantity,
                    $usedQtyTracker
                );


                $totalAllocated = collect($allocatedQtys)->sum('quantity');

                if ($totalAllocated < $basequantity) {
                    DB::rollBack();
                    throw new Exception('Return quantity cannot be greater than purchased quantity for product ID: ' . $product['product_id']);
                }


                foreach ($allocatedQtys as $alloc) {

                    $transactionData = [

                        'stock_id' => $stock->id,
                        'fiscal_year_id' => $fiscalYearId,
                        'source_id' => $alloc['source_id'] ?? null,
                        'source_type' => $alloc['source_type'] ?? null,
                        'stock_product_id' => $alloc['stock_product_id'] ?? null,
                        'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,
                        'product_id' => $product['product_id'],
                        'measure_unit_id' => $product['measure_unit_id'],
                        'type' => 'purchase_return',
                        'quantity' => $alloc['quantity'],
                        'is_vatable' => $product['is_vatable'],
                        'stock_type' => 'regular',
                        'company_id' => $data['company_id'],
                        'branch_id' => $data['branch_id'],
                        'direction' => 'in',
                        'party_id' => $data['party_id'] ?? null,
                        'expiry_date' => $product['expiry_date'] ?? null,
                        'mfd' => $product['mfd'] ?? null,
                        'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                        'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                        'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                        'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                        'batch_no' => $product['batch_no'] ?? null,
                        'tracker_id' => $trackerId,

                    ];
                    $stockTransaction = StockTransaction::create($transactionData);
                }


                if (!empty($product['free_quantity']) && $product['free_quantity'] > 0) {
                    $freeQuantity = $this->unitConversionService->convertToBaseUnit(
                        $product['measure_unit_id'],
                        $product['free_quantity']
                    );

                    $freeAllocated = $this->quantityAllocationService->allocateBillWiseQuantity(
                        $purchaseStock->id,
                        $product['product_id'],
                        $branchId,
                        $freeQuantity,
                        $usedQtyTracker
                    );

                    foreach ($freeAllocated as $alloc) {

                        $movementValidatedData = [
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id,
                            'source_id' => $alloc['source_id'] ?? null,
                            'source_type' => $alloc['source_type'] ?? null,
                            'fiscal_year_id' => $fiscalYearId,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $alloc['quantity'],
                            'stock_type' => 'free',
                            'is_vatable' => $product['is_vatable'],
                            'direction' => 'in',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => null,
                            'discount_percent' => null,
                            'discount_amount' => null,
                            'amount' => null,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $alloc['stock_product_id'] ?? null,
                            'tracker_id' => $trackerId,

                        ];
                        $stockMovementProduct = StockMovement::create($movementValidatedData);
                    }


                }
            } else {

                //@todo: handle the case when field values are present and make sure the quantity allocation is done based on the field values and also the transaction pivots are created correctly based on the field values
                $groupedFieldValues = [];


                foreach ($product['field_values'] as $group) {
                    foreach ($group as $field) {
                        $quantityType = $field['quantity_type'] ?? 'regular';
                        $key = $field['stock_product_id'] . '-' . $quantityType;

                        if (!isset($groupedFieldValues[$key])) {
                            $groupedFieldValues[$key] = [
                                'stock_product_id' => $field['stock_product_id'],
                                'quantity_type' => $quantityType,
                                'quantity_sum' => 0,
                                'fields' => [],
                            ];
                        }

                        $groupedFieldValues[$key]['quantity_sum'] += 1;
                        $groupedFieldValues[$key]['fields'][] = $field;
                    }
                }

                foreach ($groupedFieldValues as $groupData) {
                    $isFree = $groupData['quantity_type'] === 'free';
                    $quantity = $groupData['quantity_sum'];

                    $sourceType = !empty($groupData['stock_movement_id']) ? 'stock_movement' : 'stock_product';
                    $sourceId = !empty($groupData['stock_movement_id']) ? $groupData['stock_movement_id'] : $groupData['stock_product_id'];

                    if (!$isFree) {

                        $stockTransactionData = [
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'source_type' => $sourceType,
                            'source_id' => $sourceId,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $quantity,
                            'is_vatable' => $product['is_vatable'],
                            'stock_type' => 'regular',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'direction' => 'out',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                            'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                            'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                            'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $groupData['stock_product_id'],
                            'tracker_id' => $trackerId,

                        ];

                        $stockTransaction = StockTransaction::create($stockTransactionData);

                    } elseif ($isFree) {

                        $movementValidtedData = [
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'source_type' => $sourceType,
                            'source_id' => $sourceId,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $quantity,
                            'stock_type' => 'free',
                            'is_vatable' => $product['is_vatable'],
                            'direction' => 'out',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => null,
                            'discount_percent' => null,
                            'discount_amount' => null,
                            'amount' => null,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $groupData['stock_product_id'],
                            'tracker_id' => $trackerId,
                        ];

                        $stockMovement = StockMovement::create($movementValidtedData);

                    }


                    foreach ($groupData['fields'] as $field) {
                        $transactionPivotData = [
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'purchase_return',
                            'direction' => 'out',
                            'stock_product_id' => $field['stock_product_id'],
                            'stock_transaction_id' => $stockTransaction->id,
                            'stock_movement_id' => $isFree ? $stockMovement->id : null,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => $field['quantity_type'] ?? 'regular',


                        ];
                        TransactionPivot::create($transactionPivotData);
                    }
                }
            }
            $usedQtyTracker[$product['product_id']] =
                ($usedQtyTracker[$product['product_id']] ?? 0) + ($remainingQty ?? 0);
        }

        $voucherReport = $this->voucherReportService->generateOutgoingVoucherReport($stock->id, 'purchase_return', $stockData);




        DB::commit();

        return $stock->load('stockTransactions');



    }

    public function update($id, array $data)
    {
        DB::beginTransaction();

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $purchaseBillNumber = $data['return_bill_no'];


        $purchaseStock = Stock::where('bill_number', $purchaseBillNumber)
            ->where('type', 'purchase')
            ->whereNull('deleted_at')
            ->first();




        if (!$purchaseStock) {
            throw new Exception('Purchase bill not found.');
        }

        $stock = Stock::findOrFail($id);




        $stockData = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'purchase_return',

            'bill_number' => $data['bill_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'pan_number' => $data['pan_number'] ?? null,
            'balance' => $this->currencyFormatService->cleanCurrency($data['balance'] ?? 0) ?? 0,
            'ref_bill_number' => $data['ref_bill_number'] ?? null,
            'return_bill_no' => $data['return_bill_no'] ?? null,
            'reasons' => $data['reasons'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $this->currencyFormatService->cleanCurrency($data['discount_value'] ?? 0) ?? 0,
            'discount_after_vat' => $this->currencyFormatService->cleanCurrency($data['discount_after_vat'] ?? 0) ?? 0,
            'sub_total_before_discount' => $this->currencyFormatService->cleanCurrency($data['sub_total_before_discount'] ?? 0) ?? 0,
            'taxable_amount' => $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0,
            'non_taxable_amount' => $this->currencyFormatService->cleanCurrency($data['non_taxable_amount'] ?? 0) ?? 0,
            'excise_duty' => $this->currencyFormatService->cleanCurrency($data['excise_duty'] ?? 0) ?? 0,
            'vat_percent' => $data['vat_percent'] ?? 0,
            'health_insurance' => $this->currencyFormatService->cleanCurrency($data['health_insurance'] ?? 0) ?? 0,
            'freight_amount' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $this->currencyFormatService->cleanCurrency($data['roundoff_amount'] ?? 0) ?? 0,
            'total_amount' => $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0,
            'payment' => json_encode($data['payment']) ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];

        $stock->update($stockData);
        $panExists = Party::where('pan_number', $data['pan_number'])
            ->where('id', '!=', $data['party_id'])
            ->exists();

        if ($panExists) {

            throw new HttpResponseException(response()->json([
                'error' => 'An error occurred while creating the stock',
                'messages' => [
                    'PAN number already exists.'
                ],
                'status' => 422
            ], 422));

        }

        Party::where('id', $data['party_id'])->update([
            'pan_number' => $data['pan_number']
        ]);

        $oldTransactionIds = StockTransaction::where('stock_id', $stock->id)->pluck('id');
        $oldMovementIds = StockMovement::where('stock_id', $stock->id)->pluck('id');


        TransactionPivot::whereIn('stock_transaction_id', $oldTransactionIds)
            ->orWhereIn('stock_movement_id', $oldMovementIds)
            ->delete();


        StockTransaction::where('stock_id', $stock->id)->delete();
        StockMovement::where('stock_id', $stock->id)->delete();



        foreach ($data['stock_transactions'] as $product) {
            if (empty($product['field_values'])) {


                $basequantity = $this->unitConversionService->convertToBaseUnit(
                    $product['measure_unit_id'],
                    $product['quantity']
                );



                $allocatedQtys = $this->quantityAllocationService->allocateBillWiseQuantity(
                    $purchaseStock->id,
                    $product['product_id'],
                    $basequantity
                );


                $totalAllocated = collect($allocatedQtys)->sum('quantity');

                if ($totalAllocated < $basequantity) {
                    DB::rollBack();
                    throw new Exception('Return quantity cannot be greater than purchased quantity for product ID: ' . $product['product_id']);
                }


                foreach ($allocatedQtys as $alloc) {

                    $stockTransactionData = [

                        'stock_id' => $stock->id,
                        'fiscal_year_id' => $fiscalYearId,
                        'stock_product_id' => $alloc['stock_product_id'] ?? null,
                        'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,
                        'product_id' => $product['product_id'],
                        'measure_unit_id' => $product['measure_unit_id'],
                        'type' => 'purchase_return',
                        'quantity' => $alloc['quantity'],
                        'is_vatable' => $product['is_vatable'],
                        'stock_type' => 'regular',
                        'company_id' => $data['company_id'],
                        'branch_id' => $data['branch_id'],
                        'direction' => 'in',
                        'party_id' => $data['party_id'] ?? null,
                        'expiry_date' => $product['expiry_date'] ?? null,
                        'mfd' => $product['mfd'] ?? null,
                        'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                        'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                        'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                        'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                        'batch_no' => $product['batch_no'] ?? null,

                    ];
                    $stockTransaction = StockTransaction::create($stockTransactionData);
                }


                if (!empty($product['free_quantity']) && $product['free_quantity'] > 0) {
                    $freeQuantity = $this->unitConversionService->convertToBaseUnit(
                        $product['measure_unit_id'],
                        $product['free_quantity']
                    );

                    $freeAllocated = $this->quantityAllocationService->allocateBillWiseQuantity(
                        $purchaseStock->id,
                        $product['product_id'],
                        $freeQuantity
                    );

                    foreach ($freeAllocated as $alloc) {

                        $movementValidatedData = [
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $alloc['quantity'],
                            'stock_type' => 'free',
                            'is_vatable' => $product['is_vatable'],
                            'direction' => 'in',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => null,
                            'discount_amount' => null,
                            'amount' => null,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $alloc['stock_product_id'] ?? null,

                        ];
                        $stockMovementProduct = StockMovement::create($movementValidatedData);
                    }


                }
            } else {
                $groupedFieldValues = [];


                foreach ($product['field_values'] as $group) {
                    foreach ($group as $field) {
                        $quantityType = $field['quantity_type'] ?? 'regular';
                        $key = $field['stock_product_id'] . '-' . $quantityType;

                        if (!isset($groupedFieldValues[$key])) {
                            $groupedFieldValues[$key] = [
                                'stock_product_id' => $field['stock_product_id'],
                                'quantity_type' => $quantityType,
                                'quantity_sum' => 0,
                                'fields' => [],
                            ];
                        }

                        $groupedFieldValues[$key]['quantity_sum'] += 1;
                        $groupedFieldValues[$key]['fields'][] = $field;
                    }
                }

                foreach ($groupedFieldValues as $groupData) {
                    $isFree = $groupData['quantity_type'] === 'free';
                    $quantity = $groupData['quantity_sum'];

                    if (!$isFree) {

                        $stockTransactionData = [
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $quantity,
                            'is_vatable' => $product['is_vatable'],
                            'stock_type' => 'regular',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'direction' => 'out',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                            'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                            'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                            'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $groupData['stock_product_id'],
                        ];

                        $stockTransaction = StockTransaction::create($stockTransactionData);

                    } elseif ($isFree) {

                        $movementValidatedData = [
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $quantity,
                            'stock_type' => 'free',
                            'is_vatable' => $product['is_vatable'],
                            'direction' => 'out',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => null,
                            'discount_percent' => null,
                            'discount_amount' => null,
                            'amount' => null,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $groupData['stock_product_id'],
                        ];

                        $stockMovement = StockMovement::create($movementValidatedData);

                    }


                    foreach ($groupData['fields'] as $field) {

                        $transactionPivotData = [
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'purchase_return',
                            'direction' => 'out',
                            'stock_product_id' => $field['stock_product_id'],
                            'stock_transaction_id' => $stockTransaction->id,
                            'stock_movement_id' => $isFree ? $stockMovement->id : null,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => $field['quantity_type'] ?? 'regular',

                        ];
                        TransactionPivot::create($transactionPivotData);
                    }
                }
            }
        }

        DB::commit();

        return $stock->load('stockTransactions');


    }




    public function list(array $filters)
    {
        $stocks = Stock::where('type', 'purchase_return')
            ->whereNull('deleted_at')
            ->where('branch_id', $filters['branch_id'])
            ->with('party')
            ->paginate(1);

        $stocks->getCollection()->transform(function ($stock) {
            return [
                'id' => $stock->id,
                'fiscal_year_id' => $stock->fiscal_year_id,
                'bank_id' => $stock->bank_id,
                'branch_id' => $stock->branch_id,
                'party_id' => $stock->party_id,
                'party_name' => $stock->party?->name ?? 'N/A',
                'location_id' => $stock->location_id,
                'type' => $stock->type,
                'company_id' => $stock->company_id,
                'store_id' => $stock->store_id,
                'invoice_date' => $stock->invoice_date,
                'invoice_date_bs' => $stock->invoice_date_bs,
                'purchase_bill_number' => $stock->purchaseBill?->bill_number,
                'bill_number' => $stock->bill_number,
                'ref_bill_number' => $stock->ref_bill_number,
                'reasons' => $stock->reasons,
                'discount_type' => $stock->discount_type,
                'discount_value' => $stock->discount_value,
                'discount_after_vat' => $stock->discount_after_vat,
                'sub_total_before_discount' => $stock->sub_total_before_discount,
                'excise_duty' => $stock->excise_duty,
                'vat_percent' => $stock->vat_percent,
                'health_insurance' => $stock->health_insurance,
                'freight_amount' => $stock->freight_amount,
                'roundoff_type' => $stock->roundoff_type,
                'roundoff_amount' => $stock->roundoff_amount,
                'payment' => $stock->payment ? json_decode($stock->payment, true) : null,
                'taxable_amount' => $stock->taxable_amount,
                'non_taxable_amount' => $stock->non_taxable_amount,
                'total_amount' => $stock->total_amount,
                'remarks' => $stock->remarks,
                'created_at' => $stock->created_at,
            ];
        });

        return $stocks;
    }

    public function show($id)
    {
        $stock = Stock::with([
            'stockTransactions' => function ($query) {
                $query->whereNull('deleted_at')
                    ->with([
                        'transactionPivots' => function ($q) {
                            $q->whereNull('deleted_at');
                        }
                    ]);
            },
            'stockMovements' => function ($query) {
                $query->where('type', 'purchase_return')
                    ->where('stock_type', 'free')
                    ->whereNull('deleted_at');
            }
        ])
            ->whereNull('deleted_at')
            ->where('type', 'purchase_return')
            ->findOrFail($id);

        $mergedProducts = [];

        foreach ($stock->stockTransactions as $transaction) {

            $relatedMovements = $stock->stockMovements->filter(function ($movement) use ($transaction) {
                return $movement->product_id === $transaction->product_id
                    && $movement->measure_unit_id === $transaction->measure_unit_id;
            });

            $freeQty = $relatedMovements->sum('quantity');

            $fieldValues = $transaction->transactionPivots->toArray();


            $key = $transaction->product_id . '_' . $transaction->measure_unit_id;

            if (!isset($mergedProducts[$key])) {
                $mergedProducts[$key] = [
                    'product_id' => $transaction->product_id,
                    'measure_unit_id' => $transaction->measure_unit_id,
                    'quantity' => $transaction->quantity,
                    'free_quantity' => $freeQty,
                    'price' => $transaction->price,
                    'discount_percent' => $transaction->discount_percent,
                    'discount_amount' => $transaction->discount_amount,
                    'amount' => $transaction->amount,
                    'batch_no' => $transaction->batch_no,
                    'expiry_date' => $transaction->expiry_date,
                    'mfd' => $transaction->mfd,
                    'field_values' => $fieldValues,
                ];
            } else {
                $mergedProducts[$key]['quantity'] += $transaction->quantity;
                $mergedProducts[$key]['free_quantity'] += $freeQty;
                $mergedProducts[$key]['field_values'] = array_merge(
                    $mergedProducts[$key]['field_values'],
                    $fieldValues
                );
            }
        }


        $stock->stock_transactions = array_values($mergedProducts);
        unset($stock->stockTransactions);
        unset($stock->stockMovements);

        return $stock;
    }


    public function delete($id)
    {

        Db::beginTransaction();

        $stock = Stock::where('type', 'purchase_return')
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $stockTransactionIds = StockTransaction::where('stock_id', $stock->id)
            ->whereNull('deleted_at')
            ->pluck('id');
        $stockMovementIds = StockMovement::where('stock_id', $stock->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        TransactionPivot::whereIn('stock_transaction_id', $stockTransactionIds)
            ->whereNull('deleted_at')
            ->delete();

        TransactionPivot::whereIn('stock_movement_id', $stockMovementIds)
            ->whereNull('deleted_at')
            ->delete();

        StockMovement::where('stock_id', $stock->id)
            ->where('type', 'purchase_return')
            ->where('stock_type', 'free')
            ->whereNull('deleted_at')
            ->delete();

        StockTransaction::where('stock_id', $stock->id)
            ->delete();

        $stock->delete();

        DB::commit();
        return true;





    }
}

?>