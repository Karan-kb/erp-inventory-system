<?php

namespace App\Repositories;
use App\Models\StockMovement;
use App\Services\QuantityAllocationService;
use App\Services\TransactionImplementService;
use Illuminate\Support\Facades\DB;
use App\Models\Stock;
use App\Models\Vat;
use Exception;
use App\Models\StockProduct;
use App\Models\TransactionPivot;
use App\Models\FiscalYear;
use App\Models\Product;
use App\Models\MeasureUnit;
use App\Models\ProductList;
use Illuminate\Support\Str;
use App\Services\UnitConversionService;
use App\Services\CurrencyFormatService;

use App\Models\StockProductFieldValue;

use App\Interfaces\StockTransferRepositoryInterface;

class StockTransferRepository implements StockTransferRepositoryInterface
{

    protected $unitConversionService;
    protected $currencyFormatService;
    protected $quantityAllocationService;

    public function __construct(UnitConversionService $unitConversionService, CurrencyFormatService $currencyFormatService, QuantityAllocationService $quantityAllocationService)
    {
        $this->unitConversionService = $unitConversionService;
        $this->currencyFormatService = $currencyFormatService;
        $this->quantityAllocationService = $quantityAllocationService;

    }
    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $fiscalYearId = FiscalYear::where('status', 1)
                ->whereNull('deleted_at')
                ->value('id');

            $companyID = $data['company_id'];
            $fromBranchID = $data['branch_id'];
            $toBranchID = $data['to_branch'];


            $data['payment'] = isset($data['payment']) ? json_encode($data['payment']) : null;
            $data['fiscal_year_id'] = $fiscalYearId;
            $data['type'] = 'stock_transfer';
            $data['transfer_status'] = 0;


            $stockTransfer = Stock::create($data);
            $usedQtyTracker = [];


            if (!empty($data['stock_transfers'])) {
                foreach ($data['stock_transfers'] as $product) {
                    $trackerId = (string) Str::uuid();
                    $fieldValues = $product['field_values'] ?? [];
                    if (empty($fieldValues)) {
                        $productId = $product['product_id'];
                        $qty = $product['quantity'];

                        $baseQuantity = $this->unitConversionService->convertToBaseUnit(
                            $product['measure_unit_id'],
                            $product['quantity']
                        );


                        $allocatedQtys = $this->quantityAllocationService->allocateItemWiseWiseQuantity(
                            $productId,
                            $baseQuantity,
                            $usedQtyTracker
                        );

                        $totalAllocated = collect($allocatedQtys)->sum('quantity');

                        if ($totalAllocated < $baseQuantity) {
                            DB::rollBack();
                            throw new Exception('Transfer cannot be greater than available quantity for product ID: ' . $product['product_id']);
                        }

                        $transactionMap = [];

                        foreach ($allocatedQtys as $alloc) {
                            $transferData = [
                                'stock_id' => $stockTransfer->id,
                                'fiscal_year_id' => $fiscalYearId,
                                'source_type' => $alloc['source_type'] ?? null,
                                'source_id' => $alloc['source_id'] ?? null,
                                'stock_product_id' => $alloc['stock_product_id'] ?? null,
                                'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,
                                'product_id' => $product['product_id'],
                                'measure_unit_id' => $product['measure_unit_id'],
                                'from_branch' => $data['branch_id'],
                                'to_branch' => $toBranchID,
                                'type' => 'stock_transfer',
                                'quantity' => $alloc['quantity'],
                                'is_vatable' => $product['is_vatable'],
                                'stock_type' => 'subtract',
                                'company_id' => $data['company_id'],
                                'branch_id' => $data['branch_id'],
                                'direction' => 'out',
                                'party_id' => $data['party_id'] ?? null,
                                'expiry_date' => $product['expiry_date'] ?? null,
                                'mfd' => $product['mfd'] ?? null,
                                'transfer_status' => $data['transfer_status'],
                                'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                                'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                                'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                                'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                                'batch_no' => $product['batch_no'] ?? null,
                                'tracker_id' => $trackerId,

                            ];

                            $receivedData = [
                                'stock_id' => $stockTransfer->id,
                                'fiscal_year_id' => $fiscalYearId,
                                'source_type' => $alloc['source_type'] ?? null,
                                'source_id' => $alloc['source_id'] ?? null,
                                'stock_product_id' => $alloc['stock_product_id'] ?? null,
                                'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,
                                'product_id' => $product['product_id'],
                                'measure_unit_id' => $product['measure_unit_id'],
                                'from_branch' => $data['branch_id'],
                                'branch_id' => $toBranchID,

                                'type' => 'stock_transfer',
                                'quantity' => $alloc['quantity'],
                                'is_vatable' => $product['is_vatable'],
                                'stock_type' => 'add',
                                'company_id' => $data['company_id'],
                                'transfer_status' => $data['transfer_status'],
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
                            $transfer = StockMovement::create($transferData);
                            $receive = StockMovement::create($receivedData);

                            $transactionMap[$alloc['stock_product_id']] = $transfer;
                        }


                    } else {
                        $grouped = [];

                        foreach ($product['field_values'] as $group) {
                            foreach ($group as $field) {
                                $stockProductId = $field['stock_product_id'];
                                $stockMovementId = $field['stock_movement_id'] ?? null;


                                if (!isset($grouped[$stockProductId])) {
                                    $grouped[$stockProductId] = [
                                        'stock_movement_id' => $stockMovementId,
                                        'quantity' => 0,
                                        'fields' => []

                                    ];
                                }

                                $grouped[$stockProductId]['quantity']++;
                                $grouped[$stockProductId]['fields'][] = $field;
                            }
                        }

                        foreach ($grouped as $stockProductId => $types) {

                            $transfer = null;
                            $receive = null;

                            $stockMovementId = $types['stock_movement_id'] ?? null;

                            $sourceType = $stockMovementId ? 'stock_movement' : 'stock_product';
                            $sourceId = $stockMovementId ? $stockMovementId : $stockProductId;


                            if ($types['quantity'] > 0) {

                                $transferData = [
                                    'stock_id' => $stockTransfer->id,
                                    'fiscal_year_id' => $fiscalYearId,
                                    'source_type' => $sourceType,
                                    'source_id' => $sourceId,
                                    'product_id' => $product['product_id'],
                                    'measure_unit_id' => $product['measure_unit_id'],
                                    'type' => 'stock_transfer',
                                    'quantity' => $types['quantity'],
                                    'from_branch' => $data['branch_id'],
                                    'branch_id' => $data['branch_id'],
                                    'to_branch' => $toBranchID,
                                    'stock_type' => 'subtract',
                                    'direction' => 'out',
                                    'company_id' => $data['company_id'],
                                    'transfer_status' => $data['transfer_status'],
                                    'stock_product_id' => $stockProductId,

                                    // 'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,


                                    'is_vatable' => $product['is_vatable'],

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

                                $receiveData = [
                                    'stock_id' => $stockTransfer->id,
                                    'fiscal_year_id' => $fiscalYearId,
                                    'source_type' => $sourceType,
                                    'source_id' => $sourceId,
                                    'product_id' => $product['product_id'],
                                    'measure_unit_id' => $product['measure_unit_id'],
                                    'type' => 'stock_transfer',
                                    'quantity' => $types['quantity'],
                                    'transfer_status' => $data['transfer_status'],

                                    'stock_type' => 'add',
                                    'direction' => 'in',
                                    'company_id' => $data['company_id'],
                                    'from_branch' => $data['branch_id'],
                                    'branch_id' => $toBranchID,

                                    'stock_product_id' => $stockProductId,

                                    // 'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,


                                    'is_vatable' => $product['is_vatable'],

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
                                $transfer = StockMovement::create($transferData);
                                $receive = StockMovement::create($receiveData);
                            }


                            foreach ($types['fields'] as $field) {

                                $transactionPivotData = [
                                    'company_id' => $data['company_id'],
                                    'branch_id' => $data['branch_id'],
                                    'type' => 'stock_transfer',
                                    'stock_type' => 'subtract',
                                    'direction' => 'out',
                                    'stock_product_id' => $stockProductId,
                                    'stock_transaction_id' => null,
                                    'stock_movement_id' => $transfer?->id,
                                    'product_id' => $product['product_id'],
                                    'quantity_index' => $field['quantity_index'],
                                    'quantity_type' => 'regular',
                                ];
                                TransactionPivot::create($transactionPivotData);
                            }

                            foreach ($types['fields'] as $field) {

                                $transactionPivotData = [
                                    'company_id' => $data['company_id'],
                                    'branch_id' => $toBranchID,
                                    'type' => 'stock_transfer',
                                    'direction' => 'in',
                                    'stock_type' => 'add',
                                    'stock_product_id' => $stockProductId,
                                    'stock_transaction_id' => null,
                                    'stock_movement_id' => $receive?->id,
                                    'product_id' => $product['product_id'],
                                    'quantity_index' => $field['quantity_index'],
                                    'quantity_type' => 'regular',
                                ];
                                TransactionPivot::create($transactionPivotData);
                            }



                        }
                    }
                }
            }

            return $stockTransfer;
        });
    }

    public function update($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $fiscalYearId = FiscalYear::where('status', 1)
                ->whereNull('deleted_at')
                ->value('id');

            $companyID = $data['company_id'];
            $fromBranchID = $data['branch_id'];
            $toBranchID = $data['to_branch'];


            $data['payment'] = isset($data['payment']) ? json_encode($data['payment']) : null;
            $data['fiscal_year_id'] = $fiscalYearId;
            $data['type'] = 'stock_transfer';
            $data['transfer_status'] = 0;


            $stockTransfer = Stock::findOrFail($id);
            $stockTransfer->update($data);
            $usedQtyTracker = [];
            $stockTransfer->stockMovements()
                ->with('transactionPivots')
                ->get()
                ->each(function ($movement) {
                    $movement->transactionPivots()->delete();
                });

            $stockTransfer->stockMovements()->delete();


            if (!empty($data['stock_transfers'])) {
                foreach ($data['stock_transfers'] as $product) {
                    $trackerId = (string) Str::uuid();
                    $fieldValues = $product['field_values'] ?? [];
                    if (empty($fieldValues)) {
                        $productId = $product['product_id'];
                        $qty = $product['quantity'];

                        $baseQuantity = $this->unitConversionService->convertToBaseUnit(
                            $product['measure_unit_id'],
                            $product['quantity']
                        );


                        $allocatedQtys = $this->quantityAllocationService->allocateItemWiseWiseQuantity(
                            $productId,
                            $baseQuantity,
                            $usedQtyTracker
                        );

                        $totalAllocated = collect($allocatedQtys)->sum('quantity');

                        if ($totalAllocated < $baseQuantity) {
                            DB::rollBack();
                            throw new Exception('Transfer cannot be greater than available quantity for product ID: ' . $product['product_id']);
                        }

                        $transactionMap = [];

                        foreach ($allocatedQtys as $alloc) {
                            $transferData = [
                                'stock_id' => $stockTransfer->id,
                                'fiscal_year_id' => $fiscalYearId,
                                'source_type' => $alloc['source_type'] ?? null,
                                'source_id' => $alloc['source_id'] ?? null,
                                'stock_product_id' => $alloc['stock_product_id'] ?? null,
                                'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,
                                'product_id' => $product['product_id'],
                                'measure_unit_id' => $product['measure_unit_id'],
                                'from_branch' => $data['branch_id'],
                                'to_branch' => $toBranchID,
                                'type' => 'stock_transfer',
                                'quantity' => $alloc['quantity'],
                                'is_vatable' => $product['is_vatable'],
                                'stock_type' => 'subtract',
                                'company_id' => $data['company_id'],
                                'branch_id' => $data['branch_id'],
                                'direction' => 'out',
                                'party_id' => $data['party_id'] ?? null,
                                'expiry_date' => $product['expiry_date'] ?? null,
                                'mfd' => $product['mfd'] ?? null,
                                'transfer_status' => $data['transfer_status'],
                                'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                                'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                                'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                                'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                                'batch_no' => $product['batch_no'] ?? null,
                                'tracker_id' => $trackerId,

                            ];

                            $receivedData = [
                                'stock_id' => $stockTransfer->id,
                                'fiscal_year_id' => $fiscalYearId,
                                'source_type' => $alloc['source_type'] ?? null,
                                'source_id' => $alloc['source_id'] ?? null,
                                'stock_product_id' => $alloc['stock_product_id'] ?? null,
                                'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,
                                'product_id' => $product['product_id'],
                                'measure_unit_id' => $product['measure_unit_id'],
                                'from_branch' => $data['branch_id'],
                                'branch_id' => $toBranchID,

                                'type' => 'stock_transfer',
                                'quantity' => $alloc['quantity'],
                                'is_vatable' => $product['is_vatable'],
                                'stock_type' => 'add',
                                'company_id' => $data['company_id'],
                                'transfer_status' => $data['transfer_status'],
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
                            $transfer = StockMovement::create($transferData);
                            $receive = StockMovement::create($receivedData);

                            $transactionMap[$alloc['stock_product_id']] = $transfer;
                        }


                    } else {
                        $grouped = [];

                        foreach ($product['field_values'] as $group) {
                            foreach ($group as $field) {
                                $stockProductId = $field['stock_product_id'];
                                $stockMovementId = $field['stock_movement_id'] ?? null;


                                if (!isset($grouped[$stockProductId])) {
                                    $grouped[$stockProductId] = [
                                        'stock_movement_id' => $stockMovementId,
                                        'quantity' => 0,
                                        'fields' => []

                                    ];
                                }

                                $grouped[$stockProductId]['quantity']++;
                                $grouped[$stockProductId]['fields'][] = $field;
                            }
                        }

                        foreach ($grouped as $stockProductId => $types) {

                            $transfer = null;
                            $receive = null;

                            $stockMovementId = $types['stock_movement_id'] ?? null;

                            $sourceType = $stockMovementId ? 'stock_movement' : 'stock_product';
                            $sourceId = $stockMovementId ? $stockMovementId : $stockProductId;


                            if ($types['quantity'] > 0) {

                                $transferData = [
                                    'stock_id' => $stockTransfer->id,
                                    'fiscal_year_id' => $fiscalYearId,
                                    'source_type' => $sourceType,
                                    'source_id' => $sourceId,
                                    'product_id' => $product['product_id'],
                                    'measure_unit_id' => $product['measure_unit_id'],
                                    'type' => 'stock_transfer',
                                    'quantity' => $types['quantity'],
                                    'from_branch' => $data['branch_id'],
                                    'branch_id' => $data['branch_id'],
                                    'to_branch' => $toBranchID,
                                    'stock_type' => 'subtract',
                                    'direction' => 'out',
                                    'company_id' => $data['company_id'],
                                    'transfer_status' => $data['transfer_status'],
                                    'stock_product_id' => $stockProductId,

                                    // 'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,


                                    'is_vatable' => $product['is_vatable'],

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

                                $receiveData = [
                                    'stock_id' => $stockTransfer->id,
                                    'fiscal_year_id' => $fiscalYearId,
                                    'source_type' => $sourceType,
                                    'source_id' => $sourceId,
                                    'product_id' => $product['product_id'],
                                    'measure_unit_id' => $product['measure_unit_id'],
                                    'type' => 'stock_transfer',
                                    'quantity' => $types['quantity'],
                                    'transfer_status' => $data['transfer_status'],

                                    'stock_type' => 'add',
                                    'direction' => 'in',
                                    'company_id' => $data['company_id'],
                                    'from_branch' => $data['branch_id'],
                                    'branch_id' => $toBranchID,

                                    'stock_product_id' => $stockProductId,

                                    // 'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,


                                    'is_vatable' => $product['is_vatable'],

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
                                $transfer = StockMovement::create($transferData);
                                $receive = StockMovement::create($receiveData);
                            }


                            foreach ($types['fields'] as $field) {

                                $transactionPivotData = [
                                    'company_id' => $data['company_id'],
                                    'branch_id' => $data['branch_id'],
                                    'type' => 'stock_transfer',
                                    'stock_type' => 'subtract',
                                    'direction' => 'out',
                                    'stock_product_id' => $stockProductId,
                                    'stock_transaction_id' => null,
                                    'stock_movement_id' => $transfer?->id,
                                    'product_id' => $product['product_id'],
                                    'quantity_index' => $field['quantity_index'],
                                    'quantity_type' => 'regular',
                                ];
                                TransactionPivot::create($transactionPivotData);
                            }

                            foreach ($types['fields'] as $field) {

                                $transactionPivotData = [
                                    'company_id' => $data['company_id'],
                                    'branch_id' => $toBranchID,
                                    'type' => 'stock_transfer',
                                    'direction' => 'in',
                                    'stock_type' => 'add',
                                    'stock_product_id' => $stockProductId,
                                    'stock_transaction_id' => null,
                                    'stock_movement_id' => $receive?->id,
                                    'product_id' => $product['product_id'],
                                    'quantity_index' => $field['quantity_index'],
                                    'quantity_type' => 'regular',
                                ];
                                TransactionPivot::create($transactionPivotData);
                            }



                        }
                    }
                }
            }

            return $stockTransfer;
        });
    }

    public function list(array $filters)
    {
        $stocks = Stock::with('branch')
            ->where('type', 'stock_transfer')
            ->where('branch_id', $filters['branch_id'])
            ->whereNull('deleted_at')
            ->paginate(50);

         $stocks->getCollection()->map(function ($stock) {
            return [
                'id' => $stock->id,
                'branch_id' => $stock->branch_id,
                'branch' => $stock->branch?->name,
                'type' => $stock->type,
                'total_amount' => $stock->total_amount,

            ];
        });
        return $stocks;
    }

    public function show($id, $branchID)
    {
        $stock = Stock::with([
            'stockMovements' => function ($q) use ($branchID) {
                $q->where('branch_id', $branchID)
                    ->whereNull('deleted_at');
            },
            'stockMovements.transactionPivots',
            'stockMovements.product',

            // ensure stockProducts load
            'stockProducts' => function ($q) use ($branchID) {
                $q->where('branch_id', $branchID)
                    ->whereNull('deleted_at');
            },
            'stockProducts.stockProductFieldValues'
        ])
            ->whereNull('deleted_at')
            ->where('branch_id', $branchID)
            ->findOrFail($id);

        $stockProductsMap = $stock->stockProducts->keyBy('id');

        $stock->stockMovements->map(function ($movement) use ($stockProductsMap) {

            $product = $movement->product;
            $fieldValues = [];

            foreach ($movement->transactionPivots as $item) {

                $stockProduct = $stockProductsMap[$item->stock_product_id] ?? null;

                // try eager loaded
                $rawFields = collect(optional($stockProduct)->stockProductFieldValues ?? []);

                // fallback if empty
                if ($rawFields->isEmpty() && $item->stock_product_id) {
                    $rawFields = StockProductFieldValue::where('stock_product_id', $item->stock_product_id)
                        ->get();
                }

                // filter by quantity_index
                $filteredFields = $rawFields->where('quantity_index', $item->quantity_index);

                foreach ($filteredFields as $fv) {

                    $fieldValues[] = [
                        'stock_product_id' => $item->stock_product_id,
                        'stock_movement_id' => $item->stock_movement_id,
                        'quantity_index' => (string) $fv->quantity_index,
                        'product_id' => $item->product_id,
                        'direction' => $item->direction,
                        'stock_type' => $item->stock_type,
                        'key' => $fv->key,
                        'value' => $fv->value,
                    ];
                }
            }

            // assign flat structure
            $movement->field_values = $fieldValues;

            $movement->product_name = $product->name ?? null;
            $movement->product_code = $product->product_code ?? null;

            unset($movement->product);
            unset($movement->transactionPivots);

            return $movement;
        });

        $stock->stock_transfers = $stock->stockMovements;
        unset($stock->stockMovements);

        return $stock;
    }
    public function delete($id)
    {
        DB::beginTransaction();

        $stock = Stock::where('type', 'stock_transfer')
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $stockMovementIds = StockMovement::where('stock_id', $stock->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        StockProductFieldValue::whereIn('stock_movement_id', $stockMovementIds)
            ->whereNull('deleted_at')
            ->delete();


        StockMovement::whereIn('id', $stockMovementIds)
            ->delete();

        $stock->delete();

        DB::commit();

        return true;



    }
}

?>