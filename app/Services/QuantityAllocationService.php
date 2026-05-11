<?php
namespace App\Services;


use App\Models\StockProductFieldValue;
use App\Models\StockProduct;
use App\Models\StockTransaction;

use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
class QuantityAllocationService
{


    public function allocateBillWiseQuantity($purchaseStockId, $productID, $branchId, $baseQuantity, &$usedQtyTracker)
    {
        \Log::info('ALLOCATE START', [
            'purchaseStockId' => $purchaseStockId,
            'productID' => $productID,
            'baseQuantity' => $baseQuantity,
            'usedQtyTracker' => $usedQtyTracker,
        ]);
        if ($purchaseStockId <= 0 || $productID <= 0 || $baseQuantity <= 0) {
            \Log::error('INVALID INPUT', compact('purchaseStockId', 'productID', 'baseQuantity'));
            throw new \Exception("Invalid purchase stock, product, or quantity.");
        }

        $allocated = [];
        $remaining = $baseQuantity;
        \Log::info('FETCH STOCK PRODUCTS');



        $stockProducts = StockProduct::where('stock_id', $purchaseStockId)
            ->where('branch_id', $branchId)
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
        \Log::info('STOCK PRODUCTS COUNT', ['count' => $stockProducts->count()]);


        $stockMovements = StockMovement::where('stock_id', $purchaseStockId)
            ->where('branch_id', $branchId)
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
        \Log::info('STOCK MOVEMENTS COUNT', ['count' => $stockMovements->count()]);


        $combined = $stockProducts
            ->merge($stockMovements)
            ->sortBy('created_at')
            ->values();

        \Log::info('COMBINED LAYERS', [
            'total_layers' => $combined->count()
        ]);



        foreach ($combined as $index => $row) {
            \Log::info('PROCESSING ROW', [
                'index' => $index,
                'row' => $row,
                'remaining_before' => $remaining
            ]);

            if ($remaining <= 0) {
                \Log::info('STOPPED - NO REMAINING QTY');
                break;
            }

            if ($row['source_type'] === 'stock_product') {

                $sourceId = $row['stock_product_id'];


                $soldQty = StockTransaction::where('stock_product_id', $sourceId)
                    ->where('branch_id', $branchId)
                    ->where('type', 'sale')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $purchaseReturnQty = StockTransaction::where('stock_product_id', $sourceId)
                    ->where('branch_id', $branchId)
                    ->where('type', 'purchase_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $salesReturnQty = StockTransaction::where('source_id', $sourceId)
                    ->where('branch_id', $branchId)
                    ->where('type', 'sales_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                \Log::info('CALCULATED MOVEMENTS', [
                    'sourceId' => $sourceId,
                    'soldQty' => $soldQty,
                    'purchaseReturnQty' => $purchaseReturnQty,
                    'salesReturnQty' => $salesReturnQty,
                ]);

            } else {

                $sourceId = $row['stock_movement_id'];


                $soldQty = StockMovement::where('stock_product_id', $sourceId)
                    ->where('branch_id', $branchId)
                    ->where('stock_type', 'free')
                    ->where('type', 'sale')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $purchaseReturnQty = StockMovement::where('stock_product_id', $sourceId)
                    ->where('branch_id', $branchId)

                    ->where('stock_type', 'free')
                    ->where('type', 'purchase_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $salesReturnQty = StockMovement::where('source_id', $sourceId)
                    ->where('branch_id', $branchId)
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


            \Log::info('AVAILABLE QTY CALC', [
                'key' => $key,
                'original' => $row['original_quantity'],
                'alreadyUsed' => $alreadyUsed,
                'availableQty' => $availableQty,
            ]);

            if ($availableQty <= 0) {
                \Log::warning('SKIPPED ROW - NO AVAILABLE QTY', [
                    'key' => $key
                ]);
                continue;
            }


            $useQty = min($availableQty, $remaining);
            \Log::info('ALLOCATING', [
                'useQty' => $useQty,
                'remaining_before' => $remaining
            ]);
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
            \Log::info('UPDATED REMAINING', [
                'remaining_after' => $remaining
            ]);
        }
        \Log::info('FINAL STATE', [
            'remaining' => $remaining,
            'allocated_count' => count($allocated)
        ]);



        if ($remaining > 0) {
            \Log::error('INSUFFICIENT STOCK ERROR', [
                'productID' => $productID,
                'remaining' => $remaining
            ]);
            throw new \Exception(
                "Purchase return quantity exceeds available stock for product ID: {$productID}"
            );
        }

        return $allocated;
    }

    public function allocateItemWiseWiseQuantity($productID, $baseQuantity, $branchId, &$usedQtyTracker)
    {
        if ($productID <= 0 || $baseQuantity <= 0) {
            throw new \Exception("Invalid product or quantity.");
        }

        $allocated = [];
        $remaining = $baseQuantity;

        $stockProducts = StockProduct::where('branch_id', $branchId)
            ->where('product_id', $productID)

            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'source_type' => 'stock_product',
                    'source_id' => $item->id,
                    'original_quantity' => $item->quantity,
                    'created_at' => $item->created_at,
                    'product_id' => $item->product_id
                ];
            });

        $stockMovements = StockMovement::where('branch_id', $branchId)
            ->where('product_id', $productID)
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'source_type' => 'stock_movement',
                    'source_id' => $item->id,
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

            if ($remaining <= 0)
                break;

            $key = $row['source_type'] . '_' . $row['source_id'];

            $alreadyUsed = $usedQtyTracker[$key] ?? 0;


            $soldQty = StockTransaction::where('source_type', $row['source_type'])
                ->where('source_id', $row['source_id'])
                ->where('type', 'sale')
                ->where('branch_id', $branchId)
                ->sum('quantity');

            $soldQtyMov = StockMovement::where('source_type', $row['source_type'])
                ->where('source_id', $row['source_id'])
                ->where('type', 'sale')
                ->where('branch_id', $branchId)
                ->sum('quantity');

            $purchaseReturnQty = StockTransaction::where('source_type', $row['source_type'])
                ->where('source_id', $row['source_id'])
                ->where('type', 'purchase_return')
                ->where('branch_id', $branchId)
                ->sum('quantity');

            $purchaseReturnQtyMov = StockMovement::where('source_type', $row['source_type'])
                ->where('source_id', $row['source_id'])
                ->where('type', 'purchase_return')
                ->where('branch_id', $branchId)
                ->sum('quantity');

            $salesReturnQty = StockTransaction::where('source_type', $row['source_type'])
                ->where('source_id', $row['source_id'])
                ->where('type', 'sales_return')
                ->where('branch_id', $branchId)
                ->sum('quantity');

            $salesReturnQtyMov = StockMovement::where('source_type', $row['source_type'])
                ->where('source_id', $row['source_id'])
                ->where('type', 'sales_return')
                ->where('branch_id', $branchId)
                ->sum('quantity');


            $availableQty =
                $row['original_quantity']
                - ($soldQty + $soldQtyMov)
                - ($purchaseReturnQty + $purchaseReturnQtyMov)
                + ($salesReturnQty + $salesReturnQtyMov)
                - $alreadyUsed;

            if ($availableQty <= 0)
                continue;

            $useQty = min($availableQty, $remaining);

            $usedQtyTracker[$key] = $alreadyUsed + $useQty;

            $allocated[] = [
                'source_id' => $row['source_id'],
                'source_type' => $row['source_type'],


                'stock_product_id' => $row['source_type'] === 'stock_product' ? $row['source_id'] : null,
                'stock_movement_id' => $row['source_type'] === 'stock_movement' ? $row['source_id'] : null,

                'quantity' => $useQty,
                'product_id' => $row['product_id']
            ];

            $remaining -= $useQty;
        }

        if ($remaining > 0) {
            throw new \Exception("Not enough stock available for this return.");
        }

        return $allocated;
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


    public function allocateSaleQuantity($productID, $baseQuantity, $branchId, &$usedQtyTracker)
    {
        if ($productID <= 0 || $baseQuantity <= 0) {
            throw new \Exception("Invalid product ID or quantity for allocation.");
        }

        $allocated = [];
        $remaining = $baseQuantity;



        $stockProducts = StockProduct::where('branch_id', $branchId)
            ->where('product_id', $productID)
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



        $stockMovements = StockMovement::where('branch_id', $branchId)
            ->where('product_id', $productID)
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


        $allTransactions = StockTransaction::where('branch_id', $branchId)
            ->where('product_id', $productID)
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
                    ->where('branch_id', $branchId)
                    ->where('type', 'purchase_return')
                    ->sum('quantity');

                $saleQty = $transactions
                    ->where('branch_id', $branchId)
                    ->where('type', 'sale')
                    ->sum('quantity');

                $saleReturnQty = $transactions
                    ->where('branch_id', $branchId)
                    ->where('type', 'sale_return')
                    ->sum('quantity');

                $availableQty =
                    $row['quantity']
                    - $purchaseReturnQty
                    - $saleQty
                    + $saleReturnQty;

            } else {

                $outQty = $transactions
                    ->where('branch_id', $branchId)
                    ->whereIn('type', ['sale', 'purchase_return'])
                    ->sum('quantity');

                $inQty = $transactions
                    ->where('branch_id', $branchId)
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
    public function allocateSalesReturnQuantity($salesStockId, $productID, $branchId, $baseQuantity, &$usedQtyTracker)
    {
        $allocated = [];
        $remaining = $baseQuantity;


        $saleTransactions = StockTransaction::where('stock_id', $salesStockId)
            ->where('branch_id', $branchId)
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
            ->where('branch_id', $branchId)
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
                    ->where('branch_id', $branchId)
                    ->where('type', 'sales_return')

                    ->sum('quantity');

            } else {

                $returnedQty = StockMovement::where('source_id', $row['id'])
                    ->where('source_type', 'stock_movement')
                    ->where('branch_id', $branchId)
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




    public function allocateSalesReturnItemWiseQuantity($productID = 0, $branchId, $baseQuantity = 0, &$usedQtyTracker = [])
    {
        if ($productID <= 0 || $baseQuantity <= 0) {
            throw new \Exception("Invalid product ID or quantity for allocation.");
        }

        $allocated = [];
        $remaining = $baseQuantity;


        $saleTransactions = StockTransaction::where('product_id', $productID)
            ->where('branch_id', $branchId)
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
            ->where('branch_id', $branchId)
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
                    ->where('branch_id', $branchId)
                    ->where('type', 'sales_return')
                    ->sum('quantity');

            } else {

                $returnedQty = StockMovement::where('source_id', $row['id'])
                    ->where('source_type', 'stock_movement')
                    ->where('branch_id', $branchId)
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