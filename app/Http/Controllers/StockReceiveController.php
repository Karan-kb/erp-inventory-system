<?php

namespace App\Http\Controllers;


use App\Models\Stock;
use App\Models\StockReceiveDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class StockReceiveController extends Controller
{

    public function index(Request $request): JsonResponse
    {

        $stockReceive = Stock::where('type', 'stock_transfer')->where('to_branch', $request->branch_id)->whereNull('deleted_at')->paginate(1);


        return response()->json([
            "message" => "Stock Redeive Data List",
            "data" => $stockReceive
        ]);
    }




    public function update($id, Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [

                'transfer_status' => [
                    'required',
                    'numeric'
                ],

            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $branchId = $request->branch_id;

            $stock = Stock::findOrFail($id);
            $stock->update([
                'transfer_status' => $validated['transfer_status'],
            ]);

            $stock->stockMovements()
                ->where('stock_type', 'add')
                ->where('branch_id', $branchId)
                ->update([
                    'transfer_status' => $validated['transfer_status'],
                ]);


            return response()->json([
                "message" => "Stock Transfer Accepted",
                "data" => $stock
            ], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {


            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {



            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'stock_id' => [
                    'required',
                    'numeric'

                ],
                'transfer_status' => [
                    'required',
                    'numeric'
                ],

            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $branchId = $request->branch_id;
            $stockId = $request->stock_id;
            $stock = Stock::findOrFail($stockId);
            $stock->update([
                'transfer_status' => $validated['transfer_status'],
            ]);

            $stock->stockMovements()
                ->where('stock_type', 'add')
                ->where('branch_id', $branchId)
                ->update([
                    'transfer_status' => $validated['transfer_status'],
                ]);


            return response()->json([
                "message" => "Stock Transfer Accepted",
                "data" => $stock
            ], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {


            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {



            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function show($id): JsonResponse
    {
        try {
            $item = Stock::with([
                'stockMovements' => function ($query) {
                    $query->where('stock_type', 'add');

                }
            ])->findOrFail($id);



            return response()->json([
                'message' => 'Stock fetched successfully',
                'data' => $item
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Stock not found!'
            ], 404);

        } catch (QueryException $e) {
            return response()->json([
                'error' => $e->getMessage() // better for debugging
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = StockReceive::with('StockReceiveDetails')->findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Stock Transfer deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Tranfer not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

}
