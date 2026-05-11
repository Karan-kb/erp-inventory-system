<?php

namespace App\Services;

use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\MeasureUnit;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockProduct;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesReturnProduct;
use App\Models\PurchaseStockProductReturn;
use App\Models\PurchaseStockProductReturnFieldValue;
use App\Models\PurchaseStockProduct;
use App\Models\PurchaseProductReturn;
use App\Models\SaleProduct;
use App\Models\SalesProductFieldValue;
use App\Models\StockTransferFieldValue;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\SaleProductReturn;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;


use Illuminate\Validation\Rule;


class AvaialableProductsService
{



    public function productListforTransaction($companyId, $branchId)
    {
        $stockIn = StockProduct::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['purchase', 'opening_stock'])

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as stock_in'))
            ->groupBy('product_id');

        $movementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'purchase')

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_in'))
            ->groupBy('product_id');


        $transactionOut = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sales', 'purchase_return'])
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_out'))
            ->groupBy('product_id');

        $movementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sales', 'purchase_return'])

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('product_id');

        $adjustmentOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'stock_adjustment')
            ->where('stock_type', 'subtract')
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as adjustment_out'))
            ->groupBy('product_id');

        $adjustmentIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'stock_adjustment')
            ->where('stock_type', 'add')
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as adjustment_in'))
            ->groupBy('product_id');

        $transactionIn = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('product_id');

        $returnMovementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as return_movement_in'))
            ->groupBy('product_id');


        $products = DB::table('products as p')
            ->leftJoinSub($movementIn, 'mi', function ($join) {
                $join->on('p.id', '=', 'mi.product_id');
            })
            ->leftJoinSub($transactionOut, 'to', function ($join) {
                $join->on('p.id', '=', 'to.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('p.id', '=', 'ti.product_id');
            })
            ->leftJoinSub($stockIn, 'si', function ($join) {
                $join->on('p.id', '=', 'si.product_id');
            })
            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('p.id', '=', 'mo.product_id');
            })
            ->leftJoinSub($returnMovementIn, 'rmi', function ($join) {
                $join->on('p.id', '=', 'rmi.product_id');
            })
            ->leftJoinSub($adjustmentOut, 'ao', function ($join) {
                $join->on('p.id', '=', 'ao.product_id');
            })
            ->leftJoinSub($adjustmentIn, 'ai', function ($join) {
                $join->on('p.id', '=', 'ai.product_id');
            })

            ->select(
                'p.id',
                'p.name',
                DB::raw('
                COALESCE(mi.movement_in,0)
                + COALESCE(ti.transaction_in,0)
                - COALESCE(to.transaction_out,0)
                + COALESCE(si.stock_in,0)
                - COALESCE(mo.movement_out,0)
                + COALESCE(rmi.return_movement_in,0)
                - COALESCE(ao.adjustment_out,0)
                + COALESCE(ai.adjustment_in,0)

                as available_quantity
            ')
            )
            ->havingRaw('available_quantity > 0')
            ->get();

        return $products;
    }

    public function productListforTransactionDetails($companyId, $branchId, $productId)
    {
        $stockIn = StockProduct::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereIn('type', ['purchase', 'opening_stock'])

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as stock_in'))
            ->groupBy('product_id');

        $movementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'purchase')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_in'))
            ->groupBy('product_id');


        $transactionOut = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'purchase_return'])
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_out'))
            ->groupBy('product_id');

        $movementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'purchase_return'])
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('product_id');

        $adjustmentOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'stock_adjustment')
            ->where('stock_type', 'subtract')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as adjustment_out'))
            ->groupBy('product_id');

        $adjustmentIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'stock_adjustment')
            ->where('stock_type', 'add')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as adjustment_in'))
            ->groupBy('product_id');


        $transactionIn = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('product_id');

        $returnMovementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as return_movement_in'))
            ->groupBy('product_id');

        $transactionMovementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'stock_transfer')
            ->where('stock_type', 'subtract')
            ->where('transfer_status', '!=', 2)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_movement_out'))
            ->groupBy('product_id');

        $transactionMovementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'stock_transfer')
            ->where('stock_type', 'add')
            ->where('transfer_status', '=', 1)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_movement_in'))
            ->groupBy('product_id');


        $measureUnits = DB::table('product_lists as pl')
            ->join('measure_units as mu', 'pl.measure_unit_id', '=', 'mu.id')
            ->whereNull('pl.deleted_at')
            ->select(
                'pl.product_id',
                'pl.measure_unit_id as id',
                'mu.name',
                'mu.quantity',

                'pl.price',
                'pl.final_price',
                'pl.discount',
                'pl.final_price',
                'pl.is_primary'
            )
            ->get()
            ->groupBy('product_id');


        $products = DB::table('products as p')
            ->leftJoin('measure_units as mu', 'p.measure_unit_id', '=', 'mu.id')
            ->leftJoinSub($movementIn, 'mi', function ($join) {
                $join->on('p.id', '=', 'mi.product_id');
            })
            ->leftJoinSub($transactionOut, 'to', function ($join) {
                $join->on('p.id', '=', 'to.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('p.id', '=', 'ti.product_id');
            })
            ->leftJoinSub($stockIn, 'si', function ($join) {
                $join->on('p.id', '=', 'si.product_id');
            })
            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('p.id', '=', 'mo.product_id');
            })
            ->leftJoinSub($returnMovementIn, 'rmi', function ($join) {
                $join->on('p.id', '=', 'rmi.product_id');
            })
            ->leftJoinSub($adjustmentIn, 'ai', function ($join) {
                $join->on('p.id', '=', 'ai.product_id');
            })
            ->leftJoinSub($adjustmentOut, 'ao', function ($join) {
                $join->on('p.id', '=', 'ao.product_id');
            })
            ->leftJoinSub($transactionMovementOut, 'tmo', function ($join) {
                $join->on('p.id', '=', 'tmo.product_id');
            })
            ->leftJoinSub($transactionMovementIn, 'tmi', function ($join) {
                $join->on('p.id', '=', 'tmi.product_id');
            })
            ->where('p.id', $productId)

            ->select(
                'p.id',
                'p.name',
                'p.product_code',
                'p.purchase_rate',
                'p.retail_price',
                'p.wholesale_price',
                'p.mrp_price',
                'p.is_vatable',
                'p.measure_unit_id',


                'mu.name as measure_unit_name',
                'mu.quantity as measure_unit_quantity',

                DB::raw('
                COALESCE(mi.movement_in,0)
                + COALESCE(ti.transaction_in,0)
                - COALESCE(to.transaction_out,0)
                + COALESCE(si.stock_in,0)
                - COALESCE(mo.movement_out,0)
                + COALESCE(rmi.return_movement_in,0)
                - COALESCE(ao.adjustment_out,0)
                + COALESCE(ai.adjustment_in,0)
                -COALESCE(tmo.transaction_movement_out,0)
                +COALESCE(tmi.transaction_movement_in,0)
                

                as available_quantity
            ')
            )
            // ->havingRaw('available_quantity > 0')
            ->get();


        $variantIndexes = DB::table('stock_product_field_values')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', 'stock_product_id', 'stock_movement_id', 'quantity_index', 'key', 'value')
            ->groupBy('product_id', 'stock_product_id', 'stock_movement_id', 'quantity_index', 'key', 'value')
            ->get();




        $transactions = DB::table('transaction_pivots')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select(
                'product_id',
                'stock_product_id',
                'stock_movement_id',
                'quantity_index',
                DB::raw("
                SUM(
                    CASE
                        WHEN type = 'sale' THEN -1
                        WHEN type = 'purchase_return' THEN -1
                        WHEN type = 'stock_adjustment' AND stock_type = 'subtract' THEN -1
                        WHEN type = 'stock_transfer' AND stock_type = 'subtract' THEN -1
                        WHEN type = 'stock_transfer' AND stock_type = 'add' THEN 1
                      
                        WHEN type = 'sales_return' THEN 1
                        ELSE 0
                    END
                ) as effect
            ")
            )
            ->groupBy('product_id', 'stock_product_id', 'stock_movement_id', 'quantity_index')
            ->get();



        $variants = $variantIndexes->map(function ($variant) use ($transactions) {

            $effect = $transactions
                ->filter(function ($t) use ($variant) {

                    return (
                        (
                            !is_null($t->stock_product_id) &&
                            $t->stock_product_id == $variant->stock_product_id
                        )
                        ||
                        (
                            !is_null($t->stock_movement_id) &&
                            $t->stock_movement_id == $variant->stock_movement_id
                        )
                    )
                        && $t->quantity_index == $variant->quantity_index;

                })
                ->sum('effect');

            $available = 1 + $effect;

            if ($available > 0) {
                return [
                    "product_id" => $variant->product_id,
                    "stock_product_id" => $variant->stock_product_id ?? null,
                    "stock_movement_id" => $variant->stock_movement_id ?? null,
                    "quantity_index" => $variant->quantity_index,
                    "key" => $variant->key,
                    "value" => $variant->value,
                ];
            }

            return null;

        })->filter()->values();

        $products->transform(function ($product) use ($variants, $measureUnits) {

            $product->field_values = $variants
                ->where('product_id', $product->id)
                ->values();

          
            $baseUnit = [
                "id" => $product->measure_unit_id,
                "name" => $product->measure_unit_name,
                "quantity" => $product->measure_unit_quantity,
                "price" => $product->purchase_rate,


            ];

            $otherUnits = collect($measureUnits[$product->id] ?? [])
                ->map(function ($unit) {
                    return [
                        "id" => $unit->id,
                        "name" => $unit->name,
                        "quantity" => $unit->quantity,
                        "price" => $unit->price,
                        "final_price" => $unit->final_price,


                    ];
                });

            $units = collect();

            if (!empty($baseUnit['id'])) {
                $units->push($baseUnit);
            }


            $product->measure_units = collect([$baseUnit])
                ->merge($otherUnits)
                ->unique('id')
                ->values();
            return $product;
        });


        return $products;


    }


    public function productListforTransactionItemWiseSalesReturnDetails($companyId, $branchId, $productId)
    {
        $transactionIn = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sale')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('product_id');

        $movementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sale')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_in'))
            ->groupBy('product_id');


        $transactionOut = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_out'))
            ->groupBy('product_id');

        $movementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('product_id');





        $measureUnits = DB::table('product_lists as pl')
            ->join('measure_units as mu', 'pl.measure_unit_id', '=', 'mu.id')
            ->where('pl.product_id', $productId)
            ->whereNull('pl.deleted_at')
            ->select(
                'pl.product_id',
                'pl.measure_unit_id as id',
                'mu.name',
                'mu.quantity',

                'pl.price',
                'pl.discount',
                'pl.final_price',
                'pl.is_primary'
            )
            ->get()
            ->groupBy('product_id');

        $products = DB::table('products as p')
            ->leftJoin('measure_units as mu', 'p.measure_unit_id', '=', 'mu.id')
            ->leftJoinSub($movementIn, 'mi', function ($join) {
                $join->on('p.id', '=', 'mi.product_id');
            })
            ->leftJoinSub($transactionOut, 'to', function ($join) {
                $join->on('p.id', '=', 'to.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('p.id', '=', 'ti.product_id');
            })

            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('p.id', '=', 'mo.product_id');
            })


            ->select(
                'p.id',
                'p.name',
                'p.purchase_rate',
                'p.retail_price',
                'p.wholesale_price',
                'p.mrp_price',
                'p.measure_unit_id',

                'mu.name as measure_unit_name',
                'mu.quantity as measure_unit_quantity',
                DB::raw('
                COALESCE(mi.movement_in,0)
                + COALESCE(ti.transaction_in,0)
                - COALESCE(to.transaction_out,0)
               
                - COALESCE(mo.movement_out,0)
                

                as available_quantity
            ')
            )
            ->havingRaw('available_quantity > 0')
            ->get();

        $variantIndexes = DB::table('stock_product_field_values')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select(
                'product_id',
                'stock_product_id',
                'quantity_index',
                'quantity_type',
                'key',
                'value'
            )
            ->groupBy(
                'product_id',
                'stock_product_id',
                'quantity_index',
                'quantity_type',
                'key',
                'value'
            )
            ->get();




        $transactions = DB::table('transaction_pivots')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select(
                'product_id',
                'stock_product_id',
                'stock_transaction_id',
                'stock_movement_id',
                'quantity_index',
                DB::raw("
                SUM(
                    CASE
                         WHEN type = 'sale' THEN 1
                        WHEN type = 'sales_return' THEN -1
                        ELSE 0
                    END
                ) as effect
            ")
            )
            ->groupBy(
                'product_id',
                'stock_product_id',
                'stock_transaction_id',
                'stock_movement_id',
                'quantity_index'
            )
            ->get();



        $variants = $variantIndexes->map(function ($variant) use ($transactions) {

            $effect = $transactions
                ->where('stock_product_id', $variant->stock_product_id)
                ->where('quantity_index', $variant->quantity_index)
                ->sum('effect');

            // $available = $effect;

            if ($effect > 0) {
                return [
                    "product_id" => $variant->product_id,
                    "stock_product_id" => $variant->stock_product_id,
                    "stock_transaction_id" => $variant->stock_transaction_id ?? null,
                    "stock_movement_id" => $variant->stock_movement_id ?? null,
                    "quantity_index" => $variant->quantity_index,
                    "key" => $variant->key,
                    "value" => $variant->value,
                ];
            }

            return null;

        })->filter()->values();




        $products->transform(function ($product) use ($variants, $measureUnits) {

            $product->field_values = $variants
                ->where('product_id', $product->id)
                ->values();

            $baseUnit = [
                "id" => $product->measure_unit_id,
                "name" => $product->measure_unit_name,
                "quantity" => $product->measure_unit_quantity

            ];

            $otherUnits = collect($measureUnits[$product->id] ?? [])
                ->map(function ($unit) {
                    return [
                        "id" => $unit->id,
                        "name" => $unit->name,
                        "quantity" => $unit->quantity,


                    ];
                });

            $product->measure_units = collect([$baseUnit])
                ->merge($otherUnits)
                ->unique('id')
                ->values();

            return $product;
        });


        return $products;


    }


    public function productListforTransactionItemWiseSalesReturn($companyId, $branchId)
    {

        $transactionIn = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('product_id');


        $movementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sale')

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_in'))
            ->groupBy('product_id');


        $transactionOut = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_out'))
            ->groupBy('product_id');

        $movementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('product_id');


        $products = DB::table('products as p')
            ->leftJoinSub($movementIn, 'mi', function ($join) {
                $join->on('p.id', '=', 'mi.product_id');
            })
            ->leftJoinSub($transactionOut, 'to', function ($join) {
                $join->on('p.id', '=', 'to.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('p.id', '=', 'ti.product_id');
            })

            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('p.id', '=', 'mo.product_id');
            })


            ->select(
                'p.id',
                'p.name',
                DB::raw('
                COALESCE(mi.movement_in,0)
                + COALESCE(ti.transaction_in,0)
                - COALESCE(to.transaction_out,0)
                
                - COALESCE(mo.movement_out,0)
               

                as available_quantity
            ')
            )
            ->havingRaw('available_quantity > 0')
            ->get();

        return $products;
    }




    public function productListforTransactionBillWisePurchaseReturn($companyId, $branchId)
    {
        // 1. Base Purchases (Inflows from Purchase) - unchanged, good
        $base = DB::table('stock_products')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'purchase')
            ->whereNull('deleted_at')
            ->select(
                'stock_id',
                DB::raw("'stock_product' as source_type"),
                'id as source_id',
                'product_id',
                DB::raw('quantity as in_qty')
            )
            ->unionAll(
                DB::table('stock_movements')
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('type', 'purchase')
                    ->whereNull('deleted_at')
                    ->select(
                        'stock_id',
                        DB::raw("'stock_movement' as source_type"),
                        'id as source_id',
                        'product_id',
                        DB::raw('quantity as in_qty')
                    )
            );

        // 2. Outs (Sales + Purchase Return) - unchanged, correct
        $out = DB::table('stock_transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sales', 'purchase_return'])
            ->whereNull('deleted_at')
            ->select(
                'source_id as ref_id',
                'source_type',
                'product_id',
                DB::raw('SUM(quantity) as qty')
            )
            ->groupBy('source_id', 'source_type', 'product_id');

        $outMov = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sales', 'purchase_return'])
            ->whereNull('deleted_at')
            ->select(
                'source_id as ref_id',
                'source_type',
                'product_id',
                DB::raw('SUM(quantity) as qty')
            )
            ->groupBy('source_id', 'source_type', 'product_id');

        $outAll = $out->unionAll($outMov);

        // 3. INS (Sales Returns) - FIXED with tracing
        // We need 4 cases because sales return can be in stock_transactions or stock_movements,
        // and it can reference either stock_transaction or stock_movement of the sale.

        $inFromST = DB::table('stock_transactions as sr')  // sr = sales return
            ->join('stock_transactions as sale', function ($join) {
                $join->on('sr.source_id', '=', 'sale.id')
                    ->where('sr.source_type', 'stock_transaction');
            })
            ->where('sr.company_id', $companyId)
            ->where('sr.branch_id', $branchId)
            ->where('sr.type', 'sales_return')
            ->where('sale.type', 'sales')
            ->whereNull('sr.deleted_at')
            ->whereNull('sale.deleted_at')
            ->select(
                'sale.source_id as ref_id',      // ← original purchase source_id
                'sale.source_type as source_type', // ← original purchase source_type
                'sr.product_id',
                DB::raw('SUM(sr.quantity) as qty')
            )
            ->groupBy('sale.source_id', 'sale.source_type', 'sr.product_id');

        $inFromSM = DB::table('stock_transactions as sr')
            ->join('stock_movements as sale', function ($join) {
                $join->on('sr.source_id', '=', 'sale.id')
                    ->where('sr.source_type', 'stock_movement');
            })
            ->where('sr.company_id', $companyId)
            ->where('sr.branch_id', $branchId)
            ->where('sr.type', 'sales_return')
            ->where('sale.type', 'sales')
            ->whereNull('sr.deleted_at')
            ->whereNull('sale.deleted_at')
            ->select(
                'sale.source_id as ref_id',
                'sale.source_type as source_type',
                'sr.product_id',
                DB::raw('SUM(sr.quantity) as qty')
            )
            ->groupBy('sale.source_id', 'sale.source_type', 'sr.product_id');

        $inFromSTviaMov = DB::table('stock_movements as sr')
            ->join('stock_transactions as sale', function ($join) {
                $join->on('sr.source_id', '=', 'sale.id')
                    ->where('sr.source_type', 'stock_transaction');
            })
            ->where('sr.company_id', $companyId)
            ->where('sr.branch_id', $branchId)
            ->where('sr.type', 'sales_return')
            ->where('sale.type', 'sales')
            ->whereNull('sr.deleted_at')
            ->whereNull('sale.deleted_at')
            ->select(
                'sale.source_id as ref_id',
                'sale.source_type as source_type',
                'sr.product_id',
                DB::raw('SUM(sr.quantity) as qty')
            )
            ->groupBy('sale.source_id', 'sale.source_type', 'sr.product_id');

        $inFromSMviaMov = DB::table('stock_movements as sr')
            ->join('stock_movements as sale', function ($join) {
                $join->on('sr.source_id', '=', 'sale.id')
                    ->where('sr.source_type', 'stock_movement');
            })
            ->where('sr.company_id', $companyId)
            ->where('sr.branch_id', $branchId)
            ->where('sr.type', 'sales_return')
            ->where('sale.type', 'sales')
            ->whereNull('sr.deleted_at')
            ->whereNull('sale.deleted_at')
            ->select(
                'sale.source_id as ref_id',
                'sale.source_type as source_type',
                'sr.product_id',
                DB::raw('SUM(sr.quantity) as qty')
            )
            ->groupBy('sale.source_id', 'sale.source_type', 'sr.product_id');

        $inAll = $inFromST
            ->unionAll($inFromSM)
            ->unionAll($inFromSTviaMov)
            ->unionAll($inFromSMviaMov);

        // 4. Final Result
        $result = DB::table('stocks as s')
            ->leftJoinSub($base, 'b', function ($join) {
                $join->on('b.stock_id', '=', 's.id');
            })
            ->leftJoinSub($outAll, 'o', function ($join) {
                $join->on('o.ref_id', '=', 'b.source_id')
                    ->on('o.source_type', '=', 'b.source_type')
                    ->on('o.product_id', '=', 'b.product_id');
            })
            ->leftJoinSub($inAll, 'i', function ($join) {
                $join->on('i.ref_id', '=', 'b.source_id')
                    ->on('i.source_type', '=', 'b.source_type')
                    ->on('i.product_id', '=', 'b.product_id');
            })
            ->where('s.company_id', $companyId)
            ->where('s.branch_id', $branchId)
            ->where('s.type', 'purchase')
            ->whereNull('s.deleted_at')
            ->select(
                's.bill_number',
                DB::raw("
                SUM(
                    COALESCE(b.in_qty, 0)
                    - COALESCE(o.qty, 0)
                    + COALESCE(i.qty, 0)
                ) as available_quantity
            ")
            )
            ->groupBy('s.id', 's.bill_number')
            ->havingRaw('available_quantity > 0')
            ->pluck('bill_number');

        return $result;
    }




    public function productforTransactionBillWisePurchaseReturnDetails($companyId, $branchId, $billNumber)
    {
        Log::info('=== [START] productforTransactionBillWisePurchaseReturnDetails ===', [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'bill_number' => $billNumber,
            'timestamp' => now()->toDateTimeString()
        ]);

        // ====================== 1. Get Stock Details ======================
        Log::info('Step 1: Fetching stock details from stocks table', [
            'bill_number' => $billNumber,
            'company_id' => $companyId
        ]);

        $stockQuery = DB::table('stocks as s')
            ->leftJoin('parties as p', 's.party_id', '=', 'p.id')
            ->where('bill_number', $billNumber)
            ->where('company_id', $companyId);

        Log::debug('Step 1: Stock query SQL', ['sql' => $stockQuery->toSql(), 'bindings' => $stockQuery->getBindings()]);

        $stock = $stockQuery->first([
            's.id as stock_id',
            's.fiscal_year_id',
            's.company_id',
            's.branch_id',
            's.store_id',
            's.type',
            's.bill_number',
            's.purchase_bill_number',
            's.invoice_date',
            's.invoice_date_bs',
            's.party_id',
            'p.name as party_name',
            'p.pan_number as party_pan_number',
            'p.phone as party_phone',
            'p.address as party_address',
            's.location_id',
            's.batch_no',
            's.credit_days',
            's.balance',
            's.ref_bill_number',
            's.return_bill_no',
            's.reasons',
            's.discount_type',
            's.discount_value',
            's.discount_after_vat',
            's.sub_total_before_discount',
            's.taxable_amount',
            's.non_taxable_amount',
            's.excise_duty',
            's.vat_percent',
            's.health_insurance',
            's.freight_amount',
            's.roundoff_type',
            's.roundoff_amount',
            's.total_amount',
            's.payment',
            's.remarks',
        ]);

        if (!$stock) {
            Log::warning('Step 1: Bill not found', [
                'bill_number' => $billNumber,
                'company_id' => $companyId
            ]);
            return response()->json(["message" => "Bill not found !!"], 404);
        }

        $stockId = $stock->stock_id;
        Log::info('Step 1: Stock found successfully', [
            'stock_id' => $stockId,
            'party_name' => $stock->party_name,
            'total_amount' => $stock->total_amount,
            'type' => $stock->type
        ]);

        // ====================== 2. Subqueries ======================
        Log::info('Step 2: Building subqueries for quantity calculation', ['stock_id' => $stockId]);

        // --- STOCK IN (from stock_products) - FIXED: group by product_id only ---
        $stockIn = DB::table('stock_products')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('stock_id', $stockId)
            ->whereNull('deleted_at')
            ->select(
                'product_id',
                DB::raw('SUM(quantity) as stock_in'),
                DB::raw('GROUP_CONCAT(id) as stock_product_ids')
            )
            ->groupBy('product_id');

        Log::debug('Step 2a: stockIn subquery built (FIXED - group by product_id)', [
            'sql' => $stockIn->toSql(),
            'bindings' => $stockIn->getBindings()
        ]);

        // --- MOVEMENT IN (purchase movements) - FIXED: group by product_id only ---
        $movementIn = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'purchase')
            ->where('stock_id', $stockId)
            ->whereNull('deleted_at')
            ->select(
                'product_id',
                DB::raw('SUM(quantity) as movement_in'),
                DB::raw('GROUP_CONCAT(id) as movement_ids')
            )
            ->groupBy('product_id');

        Log::debug('Step 2b: movementIn subquery built (FIXED - group by product_id)', ['type' => 'purchase']);

        // --- Get IDs for filtering ---
        $stockProductIds = DB::table('stock_products')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('stock_id', $stockId)
            ->whereNull('deleted_at')
            ->pluck('id');

        $movementIds = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'purchase')
            ->where('stock_id', $stockId)
            ->whereNull('deleted_at')
            ->pluck('id');

        Log::info('Step 2c: Retrieved IDs for transaction filtering', [
            'stock_product_ids_count' => $stockProductIds->count(),
            'stock_product_ids' => $stockProductIds->toArray(),
            'movement_ids_count' => $movementIds->count(),
            'movement_ids' => $movementIds->toArray()
        ]);

        // --- TRANSACTION OUT (sale, purchase_return from stock_transactions) ---
        $transactionOut = DB::table('stock_transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'purchase_return'])
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {
                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })
                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select('product_id', DB::raw('SUM(quantity) as transaction_out'))
            ->groupBy('product_id');

        Log::debug('Step 2d: transactionOut subquery built', ['types' => ['sale', 'purchase_return']]);

        // --- MOVEMENT OUT (sale, purchase_return from stock_movements) ---
        $movementOut = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'purchase_return'])
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {
                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })
                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select('product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('product_id');

        Log::debug('Step 2e: movementOut subquery built', ['types' => ['sale', 'purchase_return']]);

        // --- TRANSACTION IN (sales_return from stock_transactions) ---
        $transactionIn = DB::table('stock_transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {
                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })
                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select('product_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('product_id');

        Log::debug('Step 2f: transactionIn subquery built', ['type' => 'sales_return']);

        // --- RETURN MOVEMENT IN (sales_return from stock_movements) ---
        $returnMovementIn = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {
                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })
                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select('product_id', DB::raw('SUM(quantity) as return_movement_in'))
            ->groupBy('product_id');


        $transferMovementOut = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)

            ->where('transfer_status', '!=', 2)

            ->where('type', 'stock_transfer')
            ->where('stock_type', 'subtract')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {
                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })
                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select('product_id', DB::raw('SUM(quantity) as transfer_movement_out'))
            ->groupBy('product_id');

        $transferMovementIn = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('transfer_status', '=', 2)
            ->where('type', 'stock_transfer')
            ->where('stock_type', 'add')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {
                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })
                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select('product_id', DB::raw('SUM(quantity) as transfer_movement_in'))
            ->groupBy('product_id');

        Log::debug('Step 2g: returnMovementIn subquery built', ['type' => 'sales_return']);

        // ====================== 3. Products Query ======================
        Log::info('Step 3: Building main products query with available_quantity calculation', [
            'formula' => 'COALESCE(si.stock_in,0) + COALESCE(mi.movement_in,0) + COALESCE(ti.transaction_in,0) + COALESCE(rmi.return_movement_in,0) - COALESCE(to.transaction_out,0) - COALESCE(mo.movement_out,0)'
        ]);

        // FIXED: Use stock_product_ids from a separate subquery for the IN clause
        $stockProductIdsSub = DB::table('stock_products')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('stock_id', $stockId)
            ->whereNull('deleted_at')
            ->pluck('id');

        $productsQuery = DB::table('products as p')
            ->leftJoinSub($stockIn, 'si', function ($join) {
                $join->on('p.id', '=', 'si.product_id');
            })
            ->leftJoinSub($movementIn, 'mi', function ($join) {
                $join->on('p.id', '=', 'mi.product_id');
            })
            ->leftJoinSub($transactionOut, 'to', function ($join) {
                $join->on('p.id', '=', 'to.product_id');
            })
            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('p.id', '=', 'mo.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('p.id', '=', 'ti.product_id');
            })
            ->leftJoinSub($returnMovementIn, 'rmi', function ($join) {
                $join->on('p.id', '=', 'rmi.product_id');
            })
            ->leftJoinSub($transferMovementOut, 'tmo', function ($join) {
                $join->on('p.id', '=', 'tmo.product_id');
            })
            ->leftJoinSub($transferMovementIn, 'tmi', function ($join) {
                $join->on('p.id', '=', 'tmi.product_id');
            })
            // FIXED: Join stock_products using IN instead of = to handle multiple stock products per product
            ->leftJoin('stock_products as sp', function ($join) use ($stockId, $companyId, $branchId, $stockProductIdsSub) {
                $join->on('p.id', '=', 'sp.product_id')
                    ->whereIn('sp.id', $stockProductIdsSub)
                    ->where('sp.stock_id', $stockId)
                    ->where('sp.company_id', $companyId)
                    ->where('sp.branch_id', $branchId)
                    ->whereNull('sp.deleted_at');
            })
            ->leftJoin('measure_units as mu', 'sp.measure_unit_id', '=', 'mu.id')
            ->select(
                'p.id',
                'p.name',
                DB::raw('
                COALESCE(si.stock_in,0)
                + COALESCE(mi.movement_in,0)
                + COALESCE(ti.transaction_in,0)
                + COALESCE(rmi.return_movement_in,0)
                - COALESCE(to.transaction_out,0)
                - COALESCE(mo.movement_out,0)
                -COALESCE(tmo.transfer_movement_out,0)
                +COALESCE(tmi.transfer_movement_in,0)
                as available_quantity
            '),
                'sp.id as stock_product_id',
                'sp.quantity',
                'sp.price',
                'sp.discount_amount',
                'sp.amount',
                'sp.batch_no',
                'sp.expiry_date',
                'sp.mfd',
                'sp.measure_unit_id',
                'mu.name as measure_unit_name',
                'mu.quantity as measure_unit_quantity',
                'sp.is_vatable'
            )
            ->havingRaw('available_quantity > 0');

        Log::debug('Step 3: Products query SQL', [
            'sql' => $productsQuery->toSql(),
            'bindings' => $productsQuery->getBindings()
        ]);

        Log::info('Step 3: Executing products query...');
        $products = $productsQuery->get();

        Log::info('Step 3: Products query executed', [
            'total_products_found' => $products->count(),
            'products' => $products->map(function ($p) {
                return [
                    'product_id' => $p->id,
                    'name' => $p->name,
                    'available_quantity' => $p->available_quantity,
                    'stock_product_id' => $p->stock_product_id,
                    'quantity' => $p->quantity,
                    'price' => $p->price
                ];
            })->toArray()
        ]);

        // ====================== 4. Variants Logic ======================
        Log::info('Step 4: Fetching variant data', ['stock_id' => $stockId]);

        $stockProductIds = DB::table('stock_products')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('stock_id', $stockId)
            ->pluck('id');

        Log::debug('Step 4a: Retrieved stock_product_ids for variants', [
            'count' => $stockProductIds->count(),
            'ids' => $stockProductIds->toArray()
        ]);

        $variantIndexes = DB::table('stock_product_field_values')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('stock_product_id', $stockProductIds)
            ->whereNull('deleted_at')
            ->select('product_id', 'stock_product_id', 'quantity_index', 'quantity_type', 'key', 'value')
            ->groupBy('product_id', 'stock_product_id', 'quantity_index', 'quantity_type', 'key', 'value')
            ->get();

        Log::info('Step 4b: Variant indexes fetched', ['total_variant_records' => $variantIndexes->count()]);

        $transactions = DB::table('transaction_pivots')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('stock_product_id', $stockProductIds)
            ->whereNull('deleted_at')
            ->select(
                'product_id',
                'stock_product_id',
                'quantity_index',
                DB::raw("
                SUM(
                    CASE
                        WHEN type = 'sale' THEN -1
                        WHEN type = 'purchase_return' THEN -1
                        WHEN type = 'stock_adjustment' THEN -1
                        WHEN type = 'stock_transfer' AND stock_type = 'subtract' THEN -1
                        WHEN type = 'stock_transfer' AND stock_type = 'add' THEN 1
                        WHEN type = 'sales_return' THEN 1
                        ELSE 0
                    END
                ) as effect
            ")
            )
            ->groupBy('product_id', 'stock_product_id', 'quantity_index')
            ->get();

        Log::info('Step 4c: Transaction pivots fetched for variant calculation !', [
            'total_transaction_records' => $transactions->count()
        ]);

        Log::info('Step 4d: Calculating available variants (base 1 + effect)...');
        $variants = $variantIndexes->map(function ($variant) use ($transactions) {
            $effect = $transactions
                ->where('stock_product_id', $variant->stock_product_id)
                ->where('quantity_index', $variant->quantity_index)
                ->sum('effect');

            $available = 1 + $effect;

            Log::debug('Step 4d: Variant availability calculated', [
                'product_id' => $variant->product_id,
                'stock_product_id' => $variant->stock_product_id,
                'quantity_index' => $variant->quantity_index,
                'effect' => $effect,
                'available' => $available,
                'included' => $available > 0
            ]);

            if ($available > 0) {
                return [
                    "product_id" => $variant->product_id,
                    "stock_product_id" => $variant->stock_product_id,
                    "quantity_index" => $variant->quantity_index,
                    "quantity_type" => $variant->quantity_type ?? null,
                    "key" => $variant->key,
                    "value" => $variant->value,
                ];
            }

            return null;
        })
            ->filter()
            ->values();

        Log::info('Step 4e: Variants filtered', [
            'total_variants_after_filter' => $variants->count()
        ]);

        Log::info('Step 4f: Transforming products with variants and measure units...');
        $products->transform(function ($product) use ($variants) {
            $productVariants = $variants->where('product_id', $product->id)->values();

            Log::debug('Step 4f: Attaching data to product', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'variant_count' => $productVariants->count(),
                'available_quantity' => $product->available_quantity
            ]);

            $product->field_values = $productVariants;

            $product->measure_unit = [
                [
                    "id" => $product->measure_unit_id,
                    "name" => $product->measure_unit_name,
                    "quantity" => $product->measure_unit_quantity
                ]
            ];

            unset($product->measure_unit_id);
            unset($product->measure_unit_name);

            return $product;
        });

        // ====================== 5. FINAL RESPONSE ======================
        $response = [
            'id' => $stock->stock_id,
            'bill_number' => $stock->bill_number ?? null,
            'invoice_date' => $stock->invoice_date ?? null,
            'invoice_date_bs' => $stock->invoice_date_bs ?? null,
            'total_amount' => $stock->total_amount ?? null,
            'party_id' => $stock->party_id ?? null,
            'party_name' => $stock->party_name ?? null,
            'party_pan_number' => $stock->party_pan_number ?? null,
            'party_phone' => $stock->party_phone ?? null,
            'party_address' => $stock->party_address ?? null,
            'fiscal_year_id' => $stock->fiscal_year_id ?? null,
            'company_id' => $stock->company_id ?? null,
            'branch_id' => $stock->branch_id ?? null,
            'store_id' => $stock->store_id ?? null,
            'type' => $stock->type ?? null,
            'purchase_bill_number' => $stock->purchase_bill_number ?? null,
            'location_id' => $stock->location_id ?? null,
            'batch_no' => $stock->batch_no ?? null,
            'credit_days' => $stock->credit_days ?? null,
            'balance' => $stock->balance ?? null,
            'ref_bill_number' => $stock->ref_bill_number ?? null,
            'return_bill_no' => $stock->return_bill_no ?? null,
            'reasons' => $stock->reasons ?? null,
            'discount_type' => $stock->discount_type ?? null,
            'discount_value' => $stock->discount_value ?? null,
            'discount_after_vat' => $stock->discount_after_vat ?? null,
            'sub_total_before_discount' => $stock->sub_total_before_discount ?? null,
            'taxable_amount' => $stock->taxable_amount ?? null,
            'non_taxable_amount' => $stock->non_taxable_amount ?? null,
            'excise_duty' => $stock->excise_duty ?? null,
            'vat_percent' => $stock->vat_percent ?? null,
            'health_insurance' => $stock->health_insurance ?? null,
            'freight_amount' => $stock->freight_amount ?? null,
            'roundoff_type' => $stock->roundoff_type ?? null,
            'roundoff_amount' => $stock->roundoff_amount ?? null,
            'payment' => $stock->payment ?? null,
            'remarks' => $stock->remarks ?? null,
            'products' => $products
        ];

        Log::info('=== [END] productforTransactionBillWisePurchaseReturnDetails ===', [
            'stock_id' => $stock->stock_id,
            'bill_number' => $stock->bill_number,
            'total_products_in_response' => $products->count(),
            'timestamp' => now()->toDateTimeString()
        ]);

        return $response;
    }

    public function productListforTransactionBillWiseSalesReturn($companyId, $branchId)
    {
        \Log::info("===== SALES RETURN BILL DEBUG START =====", compact('companyId', 'branchId'));


        $transSales = DB::table('stock_transactions as st')
            ->join('stocks as s', 'st.stock_id', '=', 's.id')
            ->where('st.company_id', $companyId)
            ->where('st.branch_id', $branchId)
            ->where('st.type', 'sale')
            ->whereNull('st.deleted_at')
            ->select(
                's.bill_number',
                'st.id as source_id',
                DB::raw("'stock_transaction' as source_type"),
                'st.quantity as qty'
            );

        $moveSales = DB::table('stock_movements as sm')
            ->where('sm.company_id', $companyId)
            ->where('sm.branch_id', $branchId)
            ->where('sm.type', 'sale')
            ->whereNull('sm.deleted_at')
            ->whereNotNull('sm.sales_bill_number')
            ->select(
                'sm.sales_bill_number as bill_number',
                'sm.id as source_id',
                DB::raw("'stock_movement' as source_type"),
                'sm.quantity as qty'
            );

        $allSales = $transSales->unionAll($moveSales);


        $returnsUnion = DB::table('stock_transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->select(
                'source_id',
                'source_type',
                'quantity as qty'
            )
            ->unionAll(
                DB::table('stock_movements')
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('type', 'sales_return')
                    ->whereNull('deleted_at')
                    ->select(
                        'source_id',
                        'source_type',
                        'quantity as qty'
                    )
            );


        $allReturns = DB::query()
            ->fromSub($returnsUnion, 'r')
            ->select(
                'source_id',
                'source_type',
                DB::raw('SUM(qty) as qty')
            )
            ->groupBy('source_id', 'source_type');


        \Log::info("---- SALES DATA ----", DB::query()->fromSub($allSales, 's')->get()->toArray());
        \Log::info("---- RETURNS DATA (AFTER FIX) ----", DB::query()->fromSub($allReturns, 'r')->get()->toArray());


        $result = DB::query()
            ->fromSub($allSales, 's')
            ->leftJoinSub($allReturns, 'r', function ($join) {
                $join->on('s.source_id', '=', 'r.source_id')
                    ->on('s.source_type', '=', 'r.source_type');
            })
            ->select(
                's.bill_number',
                DB::raw('SUM(s.qty) as total_sold'),
                DB::raw('COALESCE(SUM(r.qty),0) as total_returned'),
                DB::raw('SUM(s.qty) - COALESCE(SUM(r.qty),0) as available_quantity')
            )
            ->groupBy('s.bill_number')
            ->get();


        foreach ($result as $row) {
            \Log::info("BILL CALCULATION", [
                // 'bill_number' => $row->bill_number,
                'total_sold' => $row->total_sold,
                'total_returned' => $row->total_returned,
                'available_quantity' => $row->available_quantity,
            ]);
        }


        $final = collect($result)
            ->filter(fn($r) => $r->available_quantity > 0)
            ->pluck('bill_number')
            ->values();

        \Log::info("===== FINAL AVAILABLE BILLS =====", $final->toArray());

        return $final;
    }

    public function productforTransactionBillWiseSalesReturnDetails($companyId, $branchId, $billNumber)
    {
        $stock = DB::table('stocks as s')
            ->leftJoin('parties as p', 's.party_id', '=', 'p.id')
            ->where('s.bill_number', $billNumber)
            ->where('s.company_id', $companyId)
            ->select('s.*', 'p.name as party_name')
            ->first();

        if (!$stock) {
            return response()->json(["message" => "Bill not found !!"], 404);
        }

        $stockId = $stock->id;

        $salesTransactions = DB::table('stock_transactions')
            ->where('stock_id', $stockId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->select(
                'id as source_id',
                'stock_id',
                'product_id',
                'quantity',
                'price',
                'amount',
                'discount_amount',
                'measure_unit_id',
                DB::raw("'stock_transaction' as source_type")
            );

        $salesMovements = DB::table('stock_movements')
            ->where('stock_id', $stockId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->select(
                'id as source_id',
                'stock_id',
                'product_id',
                'quantity',
                DB::raw("NULL as price"),
                DB::raw("NULL as amount"),
                DB::raw("NULL as discount_amount"),
                DB::raw("NULL as measure_unit_id"),
                DB::raw("'stock_movement' as source_type")
            );

        $allSales = $salesTransactions->unionAll($salesMovements)->get();


        $returnTransactions = DB::table('stock_transactions')
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->select(
                'source_id',
                'source_type',
                'product_id',
                DB::raw('SUM(quantity) as return_qty')
            )
            ->groupBy('source_id', 'source_type', 'product_id');

        $returnMovements = DB::table('stock_movements')
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->select(
                'source_id',
                'source_type',
                'product_id',
                DB::raw('SUM(quantity) as return_qty')
            )
            ->groupBy('source_id', 'source_type', 'product_id');

        $allReturns = $returnTransactions->unionAll($returnMovements)->get();


        $returnMap = [];

        foreach ($allReturns as $r) {
            $key = $r->source_type . '_' . $r->source_id . '_' . $r->product_id;
            $returnMap[$key] = ($returnMap[$key] ?? 0) + $r->return_qty;
        }


        $products = [];

        foreach ($allSales as $sale) {

            $key = $sale->source_type . '_' . $sale->source_id . '_' . $sale->product_id;
            $returnedQty = $returnMap[$key] ?? 0;

            if (!isset($products[$sale->product_id])) {

                $productInfo = DB::table('products')
                    ->where('id', $sale->product_id)
                    ->first();

                $products[$sale->product_id] = [
                    "product_id" => $sale->product_id,
                    "product_name" => $productInfo->name ?? null,
                    "is_vatable" => $productInfo->is_vatable ?? null,


                    "quantity" => 0,
                    "price" => $sale->price ?? null,
                    "amount" => $sale->amount ?? null,
                    "discount_amount" => $sale->discount_amount ?? null,

                    "measure_unit_id" => $sale->measure_unit_id ?? null,
                    "measure_unit_quantity" => null,

                    "available_quantity" => 0,
                    "field_values" => [],
                    "measure_unit" => []
                ];
            }

            $products[$sale->product_id]["quantity"] += $sale->quantity;


            $products[$sale->product_id]["available_quantity"] += ($sale->quantity - $returnedQty);
        }


        foreach ($products as &$p) {

            if ($p["measure_unit_id"]) {
                $unit = DB::table('measure_units')
                    ->where('id', $p["measure_unit_id"])
                    ->first();

                if ($unit) {
                    $p["measure_unit"] = [
                        [
                            "id" => $unit->id,
                            "name" => $unit->name,
                            "quantity" => $unit->quantity
                        ]
                    ];

                    $p["measure_unit_quantity"] = $unit->quantity;
                }
            }
        }


        $variantIndexes = DB::table('transaction_pivots as tp')
            ->join('stock_transactions as st', 'tp.stock_transaction_id', '=', 'st.id')
            ->join('stocks as s', 'st.stock_id', '=', 's.id')
            ->where('tp.company_id', $companyId)
            ->where('tp.branch_id', $branchId)
            ->where('s.bill_number', $billNumber)
            ->where('tp.type', 'sale')
            ->whereNull('tp.deleted_at')
            ->select(
                'tp.product_id',
                'tp.stock_product_id',
                'tp.stock_transaction_id',
                'tp.stock_movement_id',
                'tp.quantity_index',
                'tp.quantity_type'
            )
            ->groupBy(
                'tp.product_id',
                'tp.stock_product_id',
                'tp.stock_transaction_id',
                'tp.stock_movement_id',
                'tp.quantity_index',
                'tp.quantity_type'
            )
            ->get();

        $fieldValues = DB::table('stock_product_field_values')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->get();

        $transactions = DB::table('transaction_pivots')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->select(
                'product_id',
                'stock_product_id',
                'quantity_index',
                DB::raw("
                SUM(
                    CASE
                        WHEN direction = 'out' THEN 1
                        WHEN direction = 'in' THEN -1
                        ELSE 0
                    END
                ) as effect
            ")
            )
            ->groupBy('product_id', 'stock_product_id', 'quantity_index')
            ->get();

        $variants = $variantIndexes->map(function ($variant) use ($transactions, $fieldValues) {

            $effect = $transactions
                ->where('stock_product_id', $variant->stock_product_id)
                ->where('quantity_index', $variant->quantity_index)
                ->sum('effect');

            if ($effect > 0) {

                $fields = $fieldValues
                    ->filter(function ($item) use ($variant) {
                        return (
                            $item->product_id == $variant->product_id
                            &&
                            (
                                $item->stock_product_id == $variant->stock_product_id ||
                                $item->stock_movement_id == $variant->stock_movement_id
                            )
                            &&
                            $item->quantity_index == $variant->quantity_index
                        );
                    })
                    ->values();

                return $fields->map(function ($field) use ($variant) {
                    return [
                        "product_id" => $variant->product_id,
                        "stock_product_id" => $variant->stock_product_id,
                        "stock_transaction_id" => $variant->stock_transaction_id,
                        "stock_movement_id" => $variant->stock_movement_id,
                        "quantity_index" => $variant->quantity_index,
                        "quantity_type" => $variant->quantity_type,
                        "key" => $field->key,
                        "value" => $field->value,
                    ];
                });
            }

            return collect([]);
        })
            ->filter()
            ->flatten(1)
            ->values();


        foreach ($products as &$product) {
            $product["field_values"] = $variants
                ->where("product_id", $product["product_id"])
                ->values();
        }


        return [
            'id' => $stock->id,
            'bill_number' => $stock->bill_number,
            'invoice_date' => $stock->invoice_date,
            'invoice_date_bs' => $stock->invoice_date_bs,
            'total_amount' => $stock->total_amount,
            'party_id' => $stock->party_id,
            'party_name' => $stock->party_name,
            'fiscal_year_id' => $stock->fiscal_year_id,
            'company_id' => $stock->company_id,
            'branch_id' => $stock->branch_id,
            'store_id' => $stock->store_id,
            'type' => $stock->type,
            'purchase_bill_number' => $stock->purchase_bill_number,
            'location_id' => $stock->location_id,
            'batch_no' => $stock->batch_no,
            'credit_days' => $stock->credit_days,
            'balance' => $stock->balance,
            'ref_bill_number' => $stock->ref_bill_number,
            'return_bill_no' => $stock->return_bill_no,
            'reasons' => $stock->reasons,
            'discount_type' => $stock->discount_type,
            'discount_value' => $stock->discount_value,
            'discount_after_vat' => $stock->discount_after_vat,
            'sub_total_before_discount' => $stock->sub_total_before_discount,
            'taxable_amount' => $stock->taxable_amount,
            'non_taxable_amount' => $stock->non_taxable_amount,
            'excise_duty' => $stock->excise_duty,
            'vat_percent' => $stock->vat_percent,
            'health_insurance' => $stock->health_insurance,
            'freight_amount' => $stock->freight_amount,
            'roundoff_type' => $stock->roundoff_type,
            'roundoff_amount' => $stock->roundoff_amount,
            'payment' => $stock->payment,
            'remarks' => $stock->remarks,
            'products' => array_values($products)
        ];
    }





}