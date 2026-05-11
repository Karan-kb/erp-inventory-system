<?php

namespace App\Http\Controllers;

use App\Models\StockTransfer;
use App\Models\StockTransferDetails;
use App\Repositories\StockTransferRepository;
use Illuminate\Http\JsonResponse;
use App\Services\StockTransferService;
use App\Models\StockTransferFieldValue;
use App\Models\PurchaseStockProductFieldValue;


use App\Http\Requests\StockTransferRequest\StoreRequest;
use App\Http\Requests\StockTransferRequest\UpdateRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockReceive;
use App\Models\ProductList;
use App\Models\MeasureUnit;
use App\Models\PurchaseStockProduct;
use App\Models\SalesReturnProduct;
use App\Models\SaleProduct;
use App\Models\SalesProductFieldValue;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\PurchaseProductReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;


class StockTransferController extends Controller
{


    protected $repository;

    public function __construct(StockTransferRepository $repository)
    {
        $this->repository = $repository;
    }






    public function getProductDetails(Request $request): JsonResponse
    {
        try {
            $purchaseType = $request->input('purchase_type', 'inventory');
            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');
            $productId = $request->input('product_id');
            $productName = $request->input('product_name');
            $productBarcode = $request->input('product_barcode');

            if ($productName) {
                $products = Product::where('company_id', $companyId)

                    ->where('name', $productName)

                    ->first();

                $productId = $products ? $products->id : null;
            }

            if ($productBarcode) {
                $products = Product::where('company_id', $companyId)

                    ->where('barcode', $productBarcode)

                    ->first();

                $productId = $products ? $products->id : null;
            }


            return $this->stockTransferService->getAvailableProductByIdOrName($purchaseType, $companyId, $branchId, $productId);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Product not found'], 404);
        } catch (QueryException $e) {


            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function index(Request $request): JsonResponse
    {

        try {


            $data = $this->repository->list($request->all());

            return response()->json([
                'message' => "Stock Transfer list!",
                'data' => $data->items(),
                'pagination' => [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                ]
            ]);


        } catch (ModelNotFoundException $e) {
            return response()->json([
                "message" => "Item not Found !!",
                'error' => $e->getMessage()
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                "message" => "Database error occurred !!",
                'error' => $e->getMessage()

            ], 500);

        } catch (\Exception $e) {

            return response()->json([
                "message" => "Database error occurred !!",
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function store(StoreRequest $request): JsonResponse
    {
        try {


            $data = $this->repository->create($request->validated());

            return response()->json([
                'message' => "Stock Transfer created succefully !",
                'data' => $data
            ]);


        } catch (ModelNotFoundException $e) {
            return response()->json([
                "message" => "Item not Found !!",
                'error' => $e->getMessage()
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                "message" => "Database error occurred !!",
                'error' => $e->getMessage()

            ], 500);

        } catch (\Exception $e) {

            return response()->json([
                "message" => "Database error occurred !!",
                'error' => $e->getMessage()
            ], 500);
        }
    }






    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {




            $item = $this->repository->update($id, $request->validated());

            return response()->json([
                "message" => "Stock Transfer updated successfully !!",
                "data" => $item
            ], 200);


        } catch (ModelNotFoundException $e) {
            return response()->json([
                "message" => "Item not Found !!",
                'error' => $e->getMessage()
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                "message" => "Database error occurred !!",
                'error' => $e->getMessage()

            ], 500);

        } catch (\Exception $e) {

            return response()->json([
                "message" => "Database error occurred !!",
                'error' => $e->getMessage()
            ], 500);
        }
    }








    //     private function reverseStockTransfer(StockTransfer $stockTransfer)
// {
//     try {
//         Log::info('Reversing stock transfer for field values and purchase stock products', [
//             'stock_transfer_id' => $stockTransfer->id,
//             'source_branch_id' => $stockTransfer->branch_id,
//             'target_branch_id' => $stockTransfer->transfer_to,
//         ]);

    //         $companyId = $stockTransfer->company_id;
//         $sourceBranchId = $stockTransfer->branch_id;

    //         // Check existing PurchaseStockProduct records for the stock transfer
//         $psps = PurchaseStockProduct::where('stock_transfer_id', $stockTransfer->id)
//             ->where('company_id', $companyId)
//             ->withTrashed()
//             ->get();

    //         Log::debug('PurchaseStockProduct records found for stock transfer', [
//             'stock_transfer_id' => $stockTransfer->id,
//             'psp_count' => $psps->count(),
//             'psp_details' => $psps->map(function ($psp) {
//                 return [
//                     'id' => $psp->id,
//                     'product_id' => $psp->product_id,
//                     'branch_id' => $psp->branch_id,
//                     'deleted_at' => $psp->deleted_at ? $psp->deleted_at->toDateTimeString() : null,
//                     'quantity' => $psp->quantity,
//                     'free_quantity' => $psp->free_quantity,
//                     'has_field_values' => PurchaseStockProductFieldValue::where('purchase_stock_product_id', $psp->id)->exists(),
//                 ];
//             })->toArray(),
//         ]);

    //         // Update PurchaseStockProductFieldValue records to reset to source branch
//         $fieldValueUpdatedCount = PurchaseStockProductFieldValue::where('stock_transfer_id', $stockTransfer->id)
//             ->where('company_id', $companyId)
//             ->update([
//                 'branch_id' => $sourceBranchId,
//                 'stock_transfer_id' => null,
//             ]);

    //         Log::info('Stock transfer field values reset successfully', [
//             'stock_transfer_id' => $stockTransfer->id,
//             'field_value_updated_count' => $fieldValueUpdatedCount,
//         ]);

    //         if ($fieldValueUpdatedCount === 0) {
//             Log::warning('No field values found to reset for stock transfer', [
//                 'stock_transfer_id' => $stockTransfer->id,
//             ]);
//         } else {
//             $fieldValues = PurchaseStockProductFieldValue::where('stock_transfer_id', null)
//                 ->where('company_id', $companyId)
//                 ->where('branch_id', $sourceBranchId)
//                 ->get();

    //             Log::debug('Field values after reset', [
//                 'stock_transfer_id' => $stockTransfer->id,
//                 'field_value_count' => $fieldValues->count(),
//                 'field_value_details' => $fieldValues->map(function ($fv) {
//                     return [
//                         'id' => $fv->id,
//                         'purchase_stock_product_id' => $fv->purchase_stock_product_id,
//                         'product_field_id' => $fv->product_field_id,
//                         'value' => $fv->value,
//                         'quantity_index' => $fv->quantity_index,
//                     ];
//                 })->toArray(),
//             ]);
//         }

    //         // Update PurchaseStockProduct records to reset branch_id and stock_transfer_id
//         $pspUpdatedCount = PurchaseStockProduct::where('stock_transfer_id', $stockTransfer->id)
//             ->where('company_id', $companyId)
//             ->whereNull('deleted_at')
//             ->update([
//                 'branch_id' => $sourceBranchId,
//                 'stock_transfer_id' => null,
//             ]);

    //         Log::info('Purchase stock products reset to source branch successfully', [
//             'stock_transfer_id' => $stockTransfer->id,
//             'psp_updated_count' => $pspUpdatedCount,
//             'non_field_valued_psps' => $psps->filter(function ($psp) {
//                 return !PurchaseStockProductFieldValue::where('purchase_stock_product_id', $psp->id)->exists();
//             })->count(),
//         ]);

    //         if ($pspUpdatedCount === 0) {
//             Log::warning('No purchase stock products found to reset for stock transfer', [
//                 'stock_transfer_id' => $stockTransfer->id,
//             ]);

    //             // Check PSPs linked to field values
//             $fieldValues = PurchaseStockProductFieldValue::where('stock_transfer_id', null)
//                 ->where('company_id', $companyId)
//                 ->where('branch_id', $sourceBranchId)
//                 ->pluck('purchase_stock_product_id')
//                 ->unique()
//                 ->toArray();

    //             if (!empty($fieldValues)) {
//                 $pspsLinkedToFieldValues = PurchaseStockProduct::whereIn('id', $fieldValues)
//                     ->where('company_id', $companyId)
//                     ->withTrashed()
//                     ->get();

    //                 Log::debug('PurchaseStockProduct records linked to field values', [
//                     'stock_transfer_id' => $stockTransfer->id,
//                     'psp_ids' => $fieldValues,
//                     'count' => $pspsLinkedToFieldValues->count(),
//                     'details' => $pspsLinkedToFieldValues->map(function ($psp) {
//                         return [
//                             'id' => $psp->id,
//                             'product_id' => $psp->product_id,
//                             'branch_id' => $psp->branch_id,
//                             'stock_transfer_id' => $psp->stock_transfer_id,
//                             'deleted_at' => $psp->deleted_at ? $psp->deleted_at->toDateTimeString() : null,
//                         ];
//                     })->toArray(),
//                 ]);

    //                 // Restore soft-deleted PSPs linked to field values
//                 $restoredCount = PurchaseStockProduct::whereIn('id', $fieldValues)
//                     ->where('company_id', $companyId)
//                     ->onlyTrashed()
//                     ->update([
//                         'deleted_at' => null,
//                         'branch_id' => $sourceBranchId,
//                         'stock_transfer_id' => null,
//                     ]);

    //                 Log::info('Restored soft-deleted PurchaseStockProduct records linked to field values', [
//                     'stock_transfer_id' => $stockTransfer->id,
//                     'restored_count' => $restoredCount,
//                     'psp_ids' => $fieldValues,
//                 ]);
//             }

    //             // Check PSPs linked to stock transfer details (for non-field-valued products)
//             $stockTransferDetails = $stockTransfer->stockTransferDetails()->pluck('product_id')->toArray();
//             if (!empty($stockTransferDetails)) {
//                 $pspsFromDetails = PurchaseStockProduct::whereIn('product_id', $stockTransferDetails)
//                     ->where('company_id', $companyId)
//                     ->where('stock_transfer_id', $stockTransfer->id)
//                     ->withTrashed()
//                     ->get();

    //                 Log::debug('PurchaseStockProduct records linked to stock transfer details', [
//                     'stock_transfer_id' => $stockTransfer->id,
//                     'product_ids' => $stockTransferDetails,
//                     'count' => $pspsFromDetails->count(),
//                     'details' => $pspsFromDetails->map(function ($psp) {
//                         return [
//                             'id' => $psp->id,
//                             'product_id' => $psp->product_id,
//                             'branch_id' => $psp->branch_id,
//                             'stock_transfer_id' => $psp->stock_transfer_id,
//                             'deleted_at' => $psp->deleted_at ? $psp->deleted_at->toDateTimeString() : null,
//                             'has_field_values' => PurchaseStockProductFieldValue::where('purchase_stock_product_id', $psp->id)->exists(),
//                         ];
//                     })->toArray(),
//                 ]);
//             }
//         }

    //         // Check for orphaned field values
//         $orphanedFieldValues = PurchaseStockProductFieldValue::where('stock_transfer_id', null)
//             ->where('company_id', $companyId)
//             ->where('branch_id', $sourceBranchId)
//             ->whereNotExists(function ($query) use ($companyId) {
//                 $query->select(DB::raw(1))
//                     ->from('purchase_stock_products')
//                     ->whereColumn('purchase_stock_products.id', 'purchase_stock_product_field_values.purchase_stock_product_id')
//                     ->where('purchase_stock_products.company_id', $companyId)
//                     ->whereNull('purchase_stock_products.deleted_at');
//             })
//             ->get();

    //         if ($orphanedFieldValues->isNotEmpty()) {
//             Log::warning('Found orphaned field values with no matching purchase stock products', [
//                 'stock_transfer_id' => $stockTransfer->id,
//                 'orphaned_field_value_ids' => $orphanedFieldValues->pluck('id')->toArray(),
//                 'count' => $orphanedFieldValues->count(),
//                 'details' => $orphanedFieldValues->map(function ($fv) {
//                     return [
//                         'id' => $fv->id,
//                         'purchase_stock_product_id' => $fv->purchase_stock_product_id,
//                         'product_field_id' => $fv->product_field_id,
//                         'value' => $fv->value,
//                     ];
//                 })->toArray(),
//             ]);
//         }

    //         Log::info('Stock transfer reversal completed successfully', [
//             'stock_transfer_id' => $stockTransfer->id,
//         ]);
//     } catch (\Exception $e) {
//         Log::error('Exception in reverseStockTransfer', [
//             'stock_transfer_id' => $stockTransfer->id,
//             'message' => $e->getMessage(),
//             'trace' => $e->getTraceAsString(),
//         ]);
//         throw new \Exception("Failed to reverse stock transfer ID {$stockTransfer->id}: {$e->getMessage()}");
//     }
// }

    private function getUnavailableQuantityIndices($purchaseStockProduct, $companyId)
    {
        $soldIndices = SalesProductFieldValue::whereIn('sale_product_id', $purchaseStockProduct->saleProducts->pluck('id'))
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->toArray();

        $returnedIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $purchaseStockProduct->purchaseProductReturns->pluck('id'))
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->toArray();

        return array_unique(array_merge($soldIndices, $returnedIndices));
    }

    private function calculatePieces($quantity, $measureUnitQuantity)
    {
        return floor($quantity * $measureUnitQuantity);
    }

    private function calculateAvailablePieces($purchaseStockProduct, $companyId, $measureUnitsCalc)
    {
        $purchasedPieces = $this->calculatePieces(
            ($purchaseStockProduct->quantity ?? 0) + ($purchaseStockProduct->free_quantity ?? 0),
            $measureUnitsCalc[$purchaseStockProduct->measure_unit_id]->quantity ?? 1
        );

        $returnPieces = $purchaseStockProduct->purchaseProductReturns->reduce(
            fn($carry, $return) => $carry + $this->calculatePieces(
                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                $measureUnitsCalc[$return->measure_unit_id]->quantity ?? 1
            ),
            0
        );

        $salePieces = $purchaseStockProduct->saleProducts->reduce(
            fn($carry, $sale) => $carry + $this->calculatePieces(
                ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                $measureUnitsCalc[$sale->measure_unit_id]->quantity ?? 1
            ),
            0
        );

        $salesReturnPieces = $purchaseStockProduct->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns)->reduce(
            fn($carry, $return) => $carry + $this->calculatePieces(
                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                $measureUnitsCalc[$return->measure_unit_id]->quantity ?? 1
            ),
            0
        );

        return $purchasedPieces - $returnPieces - $salePieces + $salesReturnPieces;
    }

    public function show($id, Request $request): JsonResponse
    {
        try {
            $item = $this->repository->show($id, $request->branch_id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Transfer not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }



    public function acceptStockTransfer(Request $request, $stockTransferId): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'accept_status' => 'required|in:0,1',

            ]);

            if ($validator->fails()) {

                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');

            if ($validated['accept_status'] !== 1) {

                return response()->json(['message' => 'Stock transfer not accepted, no changes made.'], 200);
            }

            $result = DB::transaction(function () use ($stockTransferId, $validated, $companyId, $branchId): array {
                // Fetch the stock transfer
                $stockTransfer = StockTransfer::with(['stockTransferDetails.fieldValues'])
                    ->where('id', $stockTransferId)
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$stockTransfer) {

                    throw new \Exception("Stock transfer ID {$stockTransferId} not found.");
                }

                if ($stockTransfer->accept_status === '1') {

                    throw new \Exception("Stock transfer ID {$stockTransferId} has already been accepted.");
                }

                // Validate transfer_to is a valid branch ID
                if (!$stockTransfer->transfer_to || !is_numeric($stockTransfer->transfer_to)) {

                    throw new \Exception("Invalid or missing transfer_to in stock transfer ID {$stockTransferId}.");
                }

                $newPurchaseStockProducts = [];
                $newFieldValues = [];

                foreach ($stockTransfer->stockTransferDetails as $detail) {
                    // Copy all data from stock_transfer_details to purchase_stock_products
                    // Set branch_id to transfer_to, do not copy purchase_stock_product_id
                    $purchaseStockProductData = [
                        'stock_adjustment_id' => $detail->stock_adjustment_id,
                        'stock_reconciliation_id' => $detail->stock_reconciliation_id,
                        'branch_id' => $stockTransfer->transfer_to, // Set to transfer_to from stock_transfers
                        'mfd' => $detail->mfd,
                        'purchase_type' => $detail->purchase_type,
                        'purchase_product_id' => $detail->purchase_product_id,
                        'stock_product_id' => $detail->stock_product_id,
                        'purchase_id' => $detail->purchase_id,
                        'product_code' => $detail->product_code,
                        'expiry_date' => $detail->expiry_date,
                        'free_quantity' => $detail->free_quantity,
                        'discount_percent' => $detail->discount_percent,
                        'discount_amount' => $detail->discount_amount,
                        'is_vatable' => $detail->is_vatable ?? 0,
                        'measure_unit_id' => $detail->measure_unit_id,
                        'stock_transfer_id' => $detail->stock_transfer_id, // Keep the same as in stock_transfer_details
                        'company_id' => $detail->company_id,
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product_name,
                        'quantity' => $detail->quantity,
                        'unit' => $detail->unit,
                        'batch_no' => $detail->batch_no,
                        'price' => $detail->price,
                        'amount' => $detail->amount,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // Create new PurchaseStockProduct (new id will be auto-generated)
                    $newPsp = PurchaseStockProduct::create($purchaseStockProductData);
                    $newPurchaseStockProducts[] = $newPsp;

                    // Handle field values if they exist - copy to purchase_stock_product_field_values
                    $fieldValues = $detail->fieldValues;
                    if ($fieldValues->isNotEmpty()) {


                        foreach ($fieldValues as $fieldValue) {
                            $newFieldValues[] = [
                                // Do not copy purchase_stock_product_field_value_id, as it should be new
                                'stock_transfer_id' => $fieldValue->stock_transfer_id, // Keep the same
                                // Keep the same
                                'company_id' => $fieldValue->company_id,
                                'branch_id' => $stockTransfer->transfer_to, // Set to transfer_to from stock_transfers
                                'purchase_stock_product_id' => $newPsp->id, // Link to new PSP id
                                'purchase_product_id' => $fieldValue->purchase_product_id,
                                'stock_product_id' => $fieldValue->stock_product_id,
                                'stock_adjustment_id' => $fieldValue->stock_adjustment_id,
                                'stock_reconciliation_id' => $fieldValue->stock_reconciliation_id,
                                'product_field_id' => $fieldValue->product_field_id,
                                'quantity_index' => $fieldValue->quantity_index,
                                'quantity_type' => $fieldValue->quantity_type,
                                'product_id' => $fieldValue->product_id,
                                'value' => $fieldValue->value,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }
                }

                // Insert new field values in batch
                if (!empty($newFieldValues)) {

                    PurchaseStockProductFieldValue::insert($newFieldValues);
                }

                // Update stock transfer accept_status
                $stockTransfer->accept_status = '1';
                $stockTransfer->save();



                return [
                    'stock_transfer' => $stockTransfer->fresh()->load('stockTransferDetails.fieldValues'),
                    'new_purchase_stock_products' => $newPurchaseStockProducts,
                ];
            });

            return response()->json([
                'message' => 'Stock transfer accepted and processed successfully.',
                'data' => $result['stock_transfer'],
            ], 200);
        } catch (\Exception $e) {

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {
            $stockTransfer = Stock::findOrFail($id);

            $usedIn = [];



            $stockTransfer->delete();

            return response()->json([
                'success' => true,
                'message' => 'Stock Transfer deleted successfully!'
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'not_found',
                'message' => 'Stock Transfer not found!'
            ], 404);
        } catch (QueryException $e) {

            return response()->json([
                'error' => 'query_error',
                'message' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the Stock Transfer.'
            ], 500);
        }
    }


}
