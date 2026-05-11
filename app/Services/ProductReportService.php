<?php
namespace App\Services;


use App\Models\StockProductFieldValue;
use App\Models\StockProduct;
use App\Models\StockTransaction;
use Illuminate\Support\Facades\Log;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
class ProductReportService
{


    public function allocateBillWiseQuantity($purchaseStockId, $productID, $baseQuantity, &$usedQtyTracker)
    {
        if ($purchaseStockId <= 0 || $productID <= 0 || $baseQuantity <= 0) {
            throw new \Exception("Invalid purchase stock, product, or quantity.");
        }

        $allocated = [];
        $remaining = $baseQuantity;


        $stockProducts = StockProduct::where('stock_id', $purchaseStockId)
            ->where('product_id', $productID)
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'source_type' => 'stock_product',
                    'stock_product_id' => $item->id,
                    'stock_movement_id' => null,
                    'original_quantity' => $item->quantity,
                    'created_at' => $item->created_at,
                    'product_id' => $item->product_id
                ];
            });


        $stockMovements = StockMovement::where('stock_id', $purchaseStockId)
            ->where('product_id', $productID)
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'source_type' => 'stock_movement',
                    'stock_product_id' => $item->stock_product_id,
                    'stock_movement_id' => $item->id,
                    'original_quantity' => $item->quantity,
                    'created_at' => $item->created_at,
                    'product_id' => $item->product_id
                ];
            });


        $combined = $stockProducts
            ->merge($stockMovements)
            ->sortBy('created_at')
            ->values();


        foreach ($combined as $row) {

            if ($remaining <= 0) {
                break;
            }

            if ($row['source_type'] === 'stock_product') {

                $sourceId = $row['stock_product_id'];


                $soldQty = StockTransaction::where('stock_product_id', $sourceId)
                    ->where('type', 'sale')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $purchaseReturnQty = StockTransaction::where('stock_product_id', $sourceId)

                    ->where('type', 'purchase_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $salesReturnQty = StockTransaction::where('source_id', $sourceId)
                    ->where('type', 'sales_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');

            } else {

                $sourceId = $row['stock_movement_id'];


                $soldQty = StockMovement::where('stock_product_id', $sourceId)
                    ->where('stock_type', 'free')
                    ->where('type', 'sale')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $purchaseReturnQty = StockMovement::where('stock_product_id', $sourceId)
                    ->where('stock_type', 'free')
                    ->where('type', 'purchase_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $salesReturnQty = StockMovement::where('source_id', $sourceId)
                    ->where('source_type', 'stock_movement')
                    ->where('type', 'sales_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');
            }


            $key = $row['source_type'] . '_' . ($row['stock_product_id'] ?? $row['stock_movement_id']);

            $alreadyUsed = $usedQtyTracker[$key] ?? 0;

            $availableQty =
                $row['original_quantity']
                - $soldQty
                - $purchaseReturnQty
                + $salesReturnQty
                - $alreadyUsed;

            if ($availableQty <= 0) {
                continue;
            }


            $useQty = min($availableQty, $remaining);
            $usedQtyTracker[$key] = ($usedQtyTracker[$key] ?? 0) + $useQty;

            $allocated[] = [
                'source_id' => $sourceId,
                'source_type' => $row['source_type'],
                'stock_product_id' => $row['stock_product_id'],
                'stock_movement_id' => $row['stock_movement_id'],
                'quantity' => $useQty,
                'product_id' => $row['product_id']
            ];

            $remaining -= $useQty;
        }


        if ($remaining > 0) {
            throw new \Exception(
                "Purchase return quantity exceeds available stock for product ID: {$productID}"
            );
        }

        return $allocated;
    }

    public static function fifoRate($productID, $companyId, $branchId)
    {
        Log::info("FIFO START", ['product_id' => $productID]);


        $primaryRow = DB::table('product_lists')
            ->where('product_id', $productID)
            ->where('is_primary', 1)
            ->first();

        $factor = DB::table('measure_units')
            ->where('id', $primaryRow->measure_unit_id ?? null)
            ->value('quantity') ?? 1;

        Log::info("PRIMARY FACTOR", ['factor' => $factor]);


        $stockProductLayers = StockProduct::where('product_id', $productID)
            ->whereNull('deleted_at')
            ->where('branch_id', $branchId)
            ->orderBy('created_at')
            ->get()
            ->map(function ($item) use ($factor) {

                $qty = $item->quantity / $factor;
                $amount = $item->amount;

                $unitCost = $qty > 0 ? $amount / $qty : 0;

                return [
                    'id' => 'SP-' . $item->id,
                    'qty' => $qty,
                    'remaining_qty' => $qty,
                    'unit_cost' => $unitCost,
                    'created_at' => $item->created_at,
                ];
            });


        $stockMovementLayers = StockMovement::where('product_id', $productID)
            ->whereNull('deleted_at')
            ->where('branch_id', $branchId)
            ->whereIn('type', ['purchase', 'sales_return', 'stock_adjustment'])
            ->where(function ($q) {
                $q->whereNull('stock_type')
                    ->orWhere('stock_type', 'add');
            })
            ->orderBy('created_at')
            ->get()
            ->map(function ($item) use ($factor) {

                $qty = $item->quantity / $factor;
                $amount = $item->amount;

                $unitCost = $qty > 0 ? $amount / $qty : 0;

                return [
                    'id' => 'SM-' . $item->id,
                    'qty' => $qty,
                    'remaining_qty' => $qty,
                    'unit_cost' => $unitCost,
                    'created_at' => $item->created_at,
                ];
            });


        $layers = $stockProductLayers
            ->merge($stockMovementLayers)
            ->sortBy('created_at')
            ->values()
            ->toArray();

        Log::info("LAYERS COUNT", ['count' => count($layers)]);


        $outQty =
            (
                StockTransaction::where('product_id', $productID)
                    ->where('branch_id', $branchId)
                    ->whereIn('type', ['sale', 'purchase_return'])

                    ->sum('quantity')
                +
                StockMovement::where('product_id', $productID)
                    ->where('branch_id', $branchId)
                    ->whereIn('type', ['sale', 'purchase_return', 'stock_adjustment'])
                    ->where('stock_type', 'subtract')
                    ->sum('quantity')
            ) / $factor;

        $inQty =
            (
                StockTransaction::where('product_id', $productID)
                    ->where('branch_id', $branchId)
                    ->where('type', 'sales_return')
                    ->sum('quantity')
                +
                StockMovement::where('product_id', $productID)
                    ->where('branch_id', $branchId)
                    ->where('type', 'sales_return')
                    ->sum('quantity')
            ) / $factor;

        $remainingOut = $outQty - $inQty;

        Log::info("OUT CALCULATION", compact('outQty', 'inQty', 'remainingOut'));


        foreach ($layers as $index => $layer) {

            if ($remainingOut <= 0)
                break;

            $deduct = min($layer['remaining_qty'], $remainingOut);

            $layers[$index]['remaining_qty'] -= $deduct;
            $remainingOut -= $deduct;

            Log::info("FIFO STEP", [
                'id' => $layer['id'],
                'deduct' => $deduct,
                'remaining_qty' => $layers[$index]['remaining_qty'],
                'remainingOut' => $remainingOut
            ]);
        }


        $totalQty = 0;
        $totalValue = 0;

        foreach ($layers as $layer) {

            if ($layer['remaining_qty'] <= 0)
                continue;

            $value = $layer['remaining_qty'] * $layer['unit_cost'];

            $totalQty += $layer['remaining_qty'];
            $totalValue += $value;

            Log::info("FINAL LAYER", [
                'id' => $layer['id'],
                'qty' => $layer['remaining_qty'],
                'unit_cost' => $layer['unit_cost'],
                'value' => $value
            ]);
        }

        $fifoRate = $totalQty > 0 ? $totalValue / $totalQty : 0;

        Log::info("FINAL RESULT", [
            'closing_qty' => $totalQty,
            'totalValue' => $totalValue,
            'fifoRate' => $fifoRate
        ]);

        return [
            'fifo_rate' => $fifoRate,
            'fifo_amount' => $totalValue,
            'closing_qty' => $totalQty,
            'layers' => $layers,
        ];
    }
    public function allocateItemWiseWiseQuantityAdjustment($productID, $baseQuantity)
    {
        if ($productID <= 0 || $baseQuantity <= 0) {
            throw new \Exception("Invalid purchase stock, product, or quantity.");
        }

        $allocated = [];
        $remaining = $baseQuantity;


        $stockProducts = StockProduct::where('product_id', $productID)
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'source_type' => 'stock_product',
                    'stock_product_id' => $item->id,
                    'stock_movement_id' => null,
                    'original_quantity' => $item->quantity,
                    'created_at' => $item->created_at,
                    'product_id' => $item->product_id
                ];
            });


        $stockMovements = StockMovement::where('product_id', $productID)
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'source_type' => 'stock_movement',
                    'stock_product_id' => $item->stock_product_id,
                    'stock_movement_id' => $item->id,
                    'original_quantity' => $item->quantity,
                    'created_at' => $item->created_at,
                    'product_id' => $item->product_id
                ];
            });


        $combined = collect(array_merge(
            $stockProducts->toArray(),
            $stockMovements->toArray()
        ))->sortBy('created_at')->values();


        foreach ($combined as $row) {

            if ($remaining <= 0) {
                break;
            }

            if ($row['source_type'] === 'stock_product') {

                $sourceId = $row['stock_product_id'];


                $soldQty = StockTransaction::where('stock_product_id', $sourceId)
                    ->where('type', 'sale')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $purchaseReturnQty = StockTransaction::where('stock_product_id', $sourceId)

                    ->where('type', 'purchase_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $salesReturnQty = StockTransaction::where('source_id', $sourceId)
                    ->where('type', 'sales_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');

            } else {

                $sourceId = $row['stock_movement_id'];


                $soldQty = StockMovement::where('stock_product_id', $sourceId)
                    ->where('stock_type', 'free')
                    ->where('type', 'sale')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $purchaseReturnQty = StockMovement::where('stock_product_id', $sourceId)
                    ->where('stock_type', 'free')
                    ->where('type', 'purchase_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $salesReturnQty = StockMovement::where('source_id', $sourceId)
                    ->where('source_type', 'stock_movement')
                    ->where('type', 'sales_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');
            }


            $availableQty =
                $row['original_quantity']
                - $soldQty
                - $purchaseReturnQty
                + $salesReturnQty;

            if ($availableQty <= 0) {
                continue;
            }


            $useQty = min($availableQty, $remaining);

            $allocated[] = [
                'source_id' => $sourceId,
                'source_type' => $row['source_type'],
                'stock_product_id' => $row['stock_product_id'],
                'stock_movement_id' => $row['stock_movement_id'],
                'quantity' => $useQty,
                'product_id' => $row['product_id']
            ];

            $remaining -= $useQty;
        }


        if ($remaining > 0) {
            throw new \Exception(
                "Purchase return quantity exceeds available stock for product ID: {$productID}"
            );
        }

        return $allocated;
    }

    // public function allocateSaleQuantity($productID, $baseQuantity)
    // {
    //     $allocated = [];
    //     $remaining = $baseQuantity;


    //     $stockProductIds = StockProduct::where('product_id', $productID)
    //         ->whereNull('deleted_at')
    //         ->pluck('id')
    //         ->toArray();

    //     if (empty($stockProductIds)) {
    //         throw new \Exception("No stock available for product ID: {$productID}");
    //     }


    //     $transactions = StockTransaction::select(
    //         'stock_product_id',
    //         'type',
    //         DB::raw('SUM(quantity) as total')
    //     )
    //         ->whereIn('stock_product_id', $stockProductIds)
    //         ->groupBy('stock_product_id', 'type')
    //         ->get()
    //         ->groupBy('stock_product_id');

    //     StockProduct::whereIn('id', $stockProductIds)
    //         ->whereNull('deleted_at')
    //         ->orderBy('id', 'asc')
    //         ->chunk(1000, function ($stockProductsChunk) use (&$allocated, &$remaining, $transactions) {

    //             foreach ($stockProductsChunk as $sp) {
    //                 if ($remaining <= 0)
    //                     break;

    //                 $txs = $transactions->get($sp->id);

    //                 $purchaseReturnQty = $txs ? $txs->where('type', 'purchase_return')->sum('total') : 0;
    //                 $saleQty = $txs ? $txs->where('type', 'sale')->sum('total') : 0;
    //                 $saleReturnQty = $txs ? $txs->where('type', 'sale_return')->sum('total') : 0;

    //                 $availableQty = $sp->quantity - $purchaseReturnQty - $saleQty + $saleReturnQty;

    //                 if ($availableQty <= 0)
    //                     continue;

    //                 $useQty = min($availableQty, $remaining);

    //                 $allocated[] = [
    //                     'source_type' => 'stock_product',
    //                     'source_id' => $sp->id,
    //                     'product_id' => $sp->product_id,
    //                     'stock_product_id' => $sp->id,
    //                     'batch_no' => $sp->batch_no,
    //                     'expiry_date' => $sp->expiry_date,
    //                     'stock_movement_id' => null,
    //                     'quantity' => $useQty
    //                 ];

    //                 $remaining -= $useQty;
    //             }
    //         });


    //     $freeMovements = StockMovement::select(
    //         'stock_product_id',
    //         'direction',
    //         DB::raw('SUM(quantity) as total')
    //     )
    //         ->where('product_id', $productID)
    //         ->whereNull('deleted_at')
    //         ->groupBy('stock_product_id', 'direction')
    //         ->get()
    //         ->groupBy('stock_product_id');

    //     foreach ($freeMovements as $stockProductId => $movs) {
    //         if ($remaining <= 0)
    //             break;

    //         $totalIn = $movs->where('direction', 'in')->sum('total');
    //         $totalOut = $movs->where('direction', 'out')->sum('total');

    //         $availableQty = $totalIn - $totalOut;

    //         if ($availableQty <= 0)
    //             continue;

    //         $useQty = min($availableQty, $remaining);

    //         $sp = StockProduct::find($stockProductId);

    //         $allocated[] = [
    //             'source_type' => 'stock_movement',
    //             'source_id' => $sp->id,
    //             'product_id' => $sp ? $sp->product_id : $productID,
    //             'stock_product_id' => $stockProductId,
    //             'batch_no' => $sp->batch_no ?? null,
    //             'expiry_date' => $sp->expiry_date ?? null,
    //             'stock_movement_id' => null,
    //             'quantity' => $useQty
    //         ];

    //         $remaining -= $useQty;
    //     }


    //     if ($remaining > 0) {
    //         throw new \Exception("Not enough stock available to allocate for product ID: {$productID}");
    //     }

    //     return $allocated;
    // }


    public function allocateSaleQuantity($productID, $baseQuantity, &$usedQtyTracker)
    {
        if ($productID <= 0 || $baseQuantity <= 0) {
            throw new \Exception("Invalid product ID or quantity for allocation.");
        }

        $allocated = [];
        $remaining = $baseQuantity;



        $stockProducts = StockProduct::where('product_id', $productID)
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'source_type' => 'stock_product',
                    'source_id' => $item->id,
                    'stock_product_id' => $item->id,
                    'stock_movement_id' => null,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'created_at' => $item->created_at,
                ];
            });



        $stockMovements = StockMovement::where('product_id', $productID)
            ->whereNull('deleted_at')
            ->where('direction', 'in')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'source_type' => 'stock_movement',
                    'source_id' => $item->id,
                    'stock_product_id' => $item->stock_product_id,
                    'stock_movement_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'created_at' => $item->created_at,
                ];
            });



        $combined = $stockProducts
            ->merge($stockMovements)
            ->sortBy('created_at')
            ->values();


        $allTransactions = StockTransaction::where('product_id', $productID)
            ->whereNull('deleted_at')
            ->get()
            ->groupBy(function ($tx) {
                return $tx->source_type . '_' . $tx->source_id;
            });



        foreach ($combined as $row) {

            if ($remaining <= 0) {
                break;
            }

            $key = $row['source_type'] . '_' . $row['source_id'];
            $alreadyUsed = $usedQtyTracker[$key] ?? 0;


            $transactions = $allTransactions->get($key) ?? collect();

            if ($row['source_type'] === 'stock_product') {

                $purchaseReturnQty = $transactions
                    ->where('type', 'purchase_return')
                    ->sum('quantity');

                $saleQty = $transactions
                    ->where('type', 'sale')
                    ->sum('quantity');

                $saleReturnQty = $transactions
                    ->where('type', 'sale_return')
                    ->sum('quantity');

                $availableQty =
                    $row['quantity']
                    - $purchaseReturnQty
                    - $saleQty
                    + $saleReturnQty;

            } else {

                $outQty = $transactions
                    ->whereIn('type', ['sale', 'purchase_return'])
                    ->sum('quantity');

                $inQty = $transactions
                    ->where('type', 'sale_return')
                    ->sum('quantity');

                $availableQty =
                    $row['quantity']
                    - $outQty
                    + $inQty;
            }

            if ($availableQty <= 0) {
                continue;
            }

            $useQty = min($availableQty, $remaining);
            $usedQtyTracker[$key] = $alreadyUsed + $useQty;

            $allocated[] = [
                'source_id' => $row['source_id'],
                'source_type' => $row['source_type'],
                'stock_product_id' => $row['stock_product_id'],
                'stock_movement_id' => $row['stock_movement_id'],
                'product_id' => $row['product_id'],
                'batch_no' => $row['batch_no'],
                'expiry_date' => $row['expiry_date'],
                'quantity' => $useQty,
            ];

            $remaining -= $useQty;
        }



        if ($remaining > 0) {
            throw new \Exception(
                "Not enough stock available to allocate for product ID: {$productID}"
            );
        }

        return $allocated;
    }
    public function allocateSalesReturnQuantity($salesStockId, $productID, $baseQuantity, &$usedQtyTracker)
    {
        $allocated = [];
        $remaining = $baseQuantity;


        $saleTransactions = StockTransaction::where('stock_id', $salesStockId)
            ->where('product_id', $productID)
            ->where('type', 'sale')

            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'source_type' => 'stock_transaction',
                    'stock_product_id' => $item->stock_product_id,
                    'quantity' => $item->quantity,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'created_at' => $item->created_at,
                ];
            });


        $saleMovements = StockMovement::where('stock_id', $salesStockId)
            ->where('product_id', $productID)
            ->where('type', 'sale')
            ->where('stock_type', 'free')
            ->where('direction', 'out')
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'source_type' => 'stock_movement',
                    'stock_product_id' => $item->stock_product_id,
                    'quantity' => $item->quantity,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'created_at' => $item->created_at,
                ];
            });


        $combined = $saleTransactions
            ->merge($saleMovements)
            ->sortBy('created_at')
            ->values();


        foreach ($combined as $row) {

            if ($remaining <= 0)
                break;


            if ($row['source_type'] === 'stock_transaction') {

                $returnedQty = StockTransaction::where('source_id', $row['id'])
                    ->where('source_type', 'stock_transaction')
                    ->where('type', 'sales_return')
                    ->sum('quantity');

            } else {

                $returnedQty = StockMovement::where('source_id', $row['id'])
                    ->where('source_type', 'stock_movement')
                    ->where('type', 'sales_return')
                    ->sum('quantity');
            }

            $availableQty = $row['quantity'] - $returnedQty;

            if ($availableQty <= 0)
                continue;

            $useQty = min($availableQty, $remaining);
            // $usedQtyTracker[$key] = $alreadyUsed + $useQty;

            $allocated[] = [
                'source_id' => $row['id'],
                'source_type' => $row['source_type'],
                'stock_product_id' => $row['stock_product_id'],
                'batch_no' => $row['batch_no'],
                'expiry_date' => $row['expiry_date'],
                'quantity' => $useQty,
            ];

            $remaining -= $useQty;
        }


        if ($remaining > 0) {
            throw new \Exception(
                "Return quantity exceeds sold quantity for product ID: {$productID}"
            );
        }

        return $allocated;
    }




    public function allocateSalesReturnItemWiseQuantity($productID = 0, $baseQuantity = 0, &$usedQtyTracker = [])
    {
        if ($productID <= 0 || $baseQuantity <= 0) {
            throw new \Exception("Invalid product ID or quantity for allocation.");
        }

        $allocated = [];
        $remaining = $baseQuantity;


        $saleTransactions = StockTransaction::where('product_id', $productID)
            ->where('type', 'sale')

            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'source_type' => 'stock_transaction',
                    'stock_product_id' => $item->stock_product_id,
                    'quantity' => $item->quantity,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'created_at' => $item->created_at,
                ];
            });


        $saleMovements = StockMovement::where('product_id', $productID)
            ->where('type', 'sale')
            ->where('stock_type', 'free')
            ->where('direction', 'out')
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'source_type' => 'stock_movement',
                    'stock_product_id' => $item->stock_product_id,
                    'quantity' => $item->quantity,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'created_at' => $item->created_at,
                ];
            });


        $combined = $saleTransactions
            ->merge($saleMovements)
            ->sortBy('created_at')
            ->values();


        foreach ($combined as $row) {

            if ($remaining <= 0)
                break;
            $key = $row['source_type'] . '_' . $row['id'];

            $alreadyUsed = $usedQtyTracker[$key] ?? 0;


            if ($row['source_type'] === 'stock_transaction') {

                $returnedQty = StockTransaction::where('source_id', $row['id'])
                    ->where('source_type', 'stock_transaction')
                    ->where('type', 'sales_return')
                    ->sum('quantity');

            } else {

                $returnedQty = StockMovement::where('source_id', $row['id'])
                    ->where('source_type', 'stock_movement')
                    ->where('type', 'sales_return')
                    ->sum('quantity');
            }

            $availableQty = $row['quantity'] - $returnedQty - $alreadyUsed;

            if ($availableQty <= 0)
                continue;

            $useQty = min($availableQty, $remaining);
            $usedQtyTracker[$key] = $alreadyUsed + $useQty;

            $allocated[] = [
                'source_id' => $row['id'],
                'source_type' => $row['source_type'],
                'stock_product_id' => $row['stock_product_id'],
                'batch_no' => $row['batch_no'],
                'expiry_date' => $row['expiry_date'],
                'quantity' => $useQty,
            ];

            $remaining -= $useQty;
        }


        if ($remaining > 0) {
            throw new \Exception(
                "Return quantity exceeds sold quantity for product ID: {$productID}"
            );
        }

        return $allocated;
    }



}
?>