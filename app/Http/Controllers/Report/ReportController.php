<?php

namespace App\Http\Controllers\Report;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Jobs\GrossProfitListExportJob;
use App\Jobs\ProductListExportJob;
use App\Jobs\StockRegisterListExportJob;
use App\Models\Customer;
use App\Models\Brand;
use App\Models\Stock;
use App\Models\Location;
use App\Models\VoucherDetails;
use App\Services\ProductReportService;
use App\Models\ProductType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSubCategory;
use App\Models\Purchase;
use App\Models\Voucher;
use App\Models\PurchaseProduct;
use App\Models\StockTransaction;
use App\Models\StockMovement;
use App\Models\StockProduct;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleProduct;
use App\Models\SalesReturn;
use App\Models\SalesReturnProduct;
use App\Models\StockEntry;
use App\Models\StockProductDetails;
use App\Reports\ProductReport;
use DB;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Validator;


class ReportController extends Controller
{

    protected $productReportService;

    public function __construct(ProductReportService $productReportService)
    {
        $this->productReportService = $productReportService;

    }
    public function productListDetails(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|string|in:list,download',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if ($request->type === "list") {

                if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
                    return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
                }

                $items = ProductReport::productListDetails($request->all());
                $items = $items->paginate(250);
                $items->getCollection()->transform(function ($item) {
                    $item->last_purchase_rate_amount = Helper::getPrimaryRateAmount($item->id, $item->lastPurchase->id ?? 0);
                    $item->last_purchase_rate_amount_vat = Helper::getProductVatableAmount($item->id, $item->last_purchase_rate_amount ?? 0);
                    $item->append('product_stock_quantity');
                    return $item;
                });
                Helper::applyCache($request->fullUrlWithQuery($request->all()), $items);

                return response()->json($items);
            } else if ($request->type === "download") {
                $user = $request->user();
                $tokenId = $user->currentAccessToken()->id;
                ProductListExportJob::dispatch($tokenId, $request->fullUrlWithQuery($request->all()));
                return response()->json([
                    'message' => 'Export started. You will receive a download link when it is ready.',

                ]);

            }
            return response()->json([]);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }

    }

    public function stockRegisterDetails(Request $request): JsonResponse
    {

        try {
            $validator = Validator::make($request->all(), [
                'method' => 'required|string|in:fifo,average',
                'type' => 'required|string|in:list,download',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            if ($request->type === "list") {

                if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
                    return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
                }
                $items = ProductReport::stockRegisterListDetails($request->all());
                $items = $items->paginate(250);
                $items->getCollection()->transform(function ($item) {
                    $item->append(['product_stock_quantity', 'opening_quantity', 'opening_rate', 'purchase_quantity', 'product_purchase_amount', 'product_purchase_rate', 'purchase_return_quantity', 'purchase_return_rate', 'purchase_return_amount', 'sale_quantity', 'product_sale_amount', 'product_sale_rate', 'sale_return_quantity', 'sale_return_rate', 'stock_adjustment_detail', 'stock_in_detail', 'stock_out_detail', 'product_closing_detail']);
                    return $item;
                });
                Helper::applyCache($request->fullUrlWithQuery($request->all()), $items);
                return response()->json($items);

            } else if ($request->type === "download") {
                $user = $request->user();
                $tokenId = $user->currentAccessToken()->id;
                StockRegisterListExportJob::dispatch($tokenId, $request->fullUrlWithQuery($request->all()));
                return response()->json([
                    'message' => 'Stock Register List export started. You will receive a download link when it is ready.',
                ]);

            }
            return response()->json([]);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function productPriceListDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:purchase,sales',
            'product_id' => 'required|numeric',
        ]);
        try {
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            $product = Product::findOrFail($request->product_id);

            if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
                return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
            }

            if ($request->type === "purchase") {
                $items = Purchase::
                    join('purchase_products', 'purchases.id', '=', 'purchase_products.purchase_id')
                    ->join('customers', 'purchases.customer_id', '=', 'customers.id')
                    ->groupBy('purchases.id', 'purchases.purchase_bill_number', 'purchases.customer_name', 'purchases.invoice_date_bs')
                    ->where('purchase_products.product_id', $request->product_id)
                    ->select([
                        'purchases.invoice_date_bs as date',
                        'customers.party_name as party_name',
                        'purchases.purchase_bill_number as invoice_number',
                        'purchases.ref_bill_number as ref_no',
                        DB::raw('SUM(purchase_products.quantity) as quantity'),
                        DB::raw('SUM(purchase_products.discount_amount) as discount_amount'),
                        DB::raw('AVG(purchase_products.price) as rate')
                    ])
                    ->when(isset($request->customer_id), function ($query) use ($request) {
                        $query->where('purchases.customer_id', $request->customer_id);
                    })
                    ->when(isset($request->from_date) && isset($request->to_date), function ($query) use ($request) {
                        $query->whereBetween('purchases.invoice_date_bs', [$request->from_date, $request->to_date]);
                    })
                    ->orderBy('purchases.invoice_date_bs', 'desc')
                    ->get();
                $items->each(function ($item) use ($product) {
                    $item->primary_unit_name = $product->getPrimaryMeasureUnitAttribute()->name;
                });
            } else {
                $items = Sale::
                    join('sale_products', 'sales.id', '=', 'sale_products.sale_id')
                    ->join('customers', 'sales.customer_id', '=', 'customers.id')
                    ->groupBy('sales.id', 'sales.invoice_date_bs', 'sales.customer_name', 'sales.invoice_number')
                    ->where('sale_products.product_id', $request->product_id)
                    ->select([
                        'sales.invoice_date_bs as date',
                        'customers.party_name as party_name',
                        'sales.invoice_number as invoice_number',
                        'sales.ref_number as ref_no',
                        DB::raw('SUM(sale_products.quantity) as quantity'),
                        DB::raw('SUM(sale_products.discount_amount) as discount_amount'),
                        DB::raw('AVG(sale_products.price) as rate')
                    ])
                    ->when(isset($request->customer_id), function ($query) use ($request) {
                        $query->where('sales.customer_id', $request->customer_id);
                    })
                    ->when(isset($request->from_date) && isset($request->to_date), function ($query) use ($request) {
                        $query->whereBetween('sales.invoice_date_bs', [$request->from_date, $request->to_date]);
                    })
                    ->orderBy('sales.invoice_date_bs', 'desc')
                    ->get();

                $items->each(function ($item) use ($product) {
                    $item->primary_unit_name = $product->getPrimaryMeasureUnitAttribute()->name;
                });

            }

            Helper::applyCache($request->fullUrlWithQuery($request->all()), $items);
            return response()->json($items);

        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Product not found'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }


    public function vendorSupplierListDetails(Request $request): JsonResponse
    {

        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|string|in:invoice,list',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if ($request->type === "list") {

                $items = Customer::select("customers.id", "customers.party_name", "customers.pan_number")->withSum('purchases', 'sub_total_before_discount')->withSum('purchases', 'discount_value')->withSum('purchaseReturns', 'sub_total_before_discount')->withSum('purchaseReturns', 'discount_value');

                if ($request->has('customer_id')) {
                    $items->where('id', $request->input('customer_id'));
                }

                $items = $items->get();
            } else {
                $items = Purchase::select("purchases.id", "purchases.invoice_date_bs", "purchases.sub_total_before_discount", "purchases.discount_value", "purchases.purchase_bill_number", "purchases.ref_bill_number", "purchases.customer_id")->with('customer:id,party_name,pan_number');

                if ($request->has('customer_id')) {
                    $items->where('id', $request->input('customer_id'));
                }

                $items = $items->get();
                $items->each->append(['purchase_return_amount', 'purchase_return_discount_amount']);
            }
            return response()->json($items);

        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function stockLedgerListDetails(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|integer|exists:products,id',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $product = Product::findOrFail($request->product_id);
            $fromDate = $request->from_date;
            $toDate = $request->to_date;

            $primaryRow = DB::table('product_lists')
                ->where('product_id', $request->product_id)
                ->where('is_primary', 1)
                ->first();

            $primaryUnit = DB::table('measure_units')
                ->where('id', $primaryRow->measure_unit_id)
                ->first();

            $factor = $primaryUnit->quantity ?? 1;

            $applyDateFilter = function ($query) use ($fromDate, $toDate) {
                return $query->when($fromDate && $toDate, function ($q) use ($fromDate, $toDate) {
                    $q->whereBetween('stocks.invoice_date_bs', [$fromDate, $toDate]);
                })
                    ->when($fromDate && !$toDate, function ($q) use ($fromDate) {
                        $q->where('stocks.invoice_date_bs', '>=', $fromDate);
                    })
                    ->when(!$fromDate && $toDate, function ($q) use ($toDate) {
                        $q->where('stocks.invoice_date_bs', '<=', $toDate);
                    });
            };

            /* ---------------- OPENING ---------------- */
            $openingItems = StockProduct::where('stock_products.type', 'opening_stock')
                ->leftJoin('stocks', 'stocks.id', '=', 'stock_products.stock_id')
                ->where('stock_products.product_id', $request->product_id)
                ->select(
                    "stock_products.id as id",
                    "stock_products.product_id as product_id",
                    "stock_products.stock_id as stock_id",
                    "stock_products.tracker_id as tracker_id",
                    "stock_products.created_at as date",
                    DB::raw("(stock_products.quantity / $factor) as opening_qty"),
                    DB::raw("0 as purchase_qty"),
                    DB::raw("0 as sale_qty"),
                    DB::raw("0 as purchase_return_qty"),
                    DB::raw("0 as sale_return_qty"),
                    "stocks.invoice_date_bs as invoice_date",
                    DB::raw("'Opening Stock' as party_name")
                )
                ->get();

            /* ---------------- PURCHASE ---------------- */
            $purchaseRegularItems = $applyDateFilter(
                StockProduct::where('stock_products.type', 'purchase')
                    ->leftJoin('stocks', 'stocks.id', '=', 'stock_products.stock_id')
                    ->leftJoin('parties', 'parties.id', '=', 'stocks.party_id')
                    ->where('stock_products.product_id', $request->product_id)
            )->select(
                    "stock_products.id as id",
                    "stock_products.id as product_id",
                    "stock_products.stock_id as stock_id",
                    "stock_products.tracker_id as tracker_id",
                    "stock_products.created_at as date",
                    DB::raw("(stock_products.quantity / $factor) as purchase_qty"),
                    DB::raw("0 as sale_qty"),
                    DB::raw("0 as purchase_return_qty"),
                    DB::raw("0 as sale_return_qty"),
                    "stocks.invoice_date_bs as invoice_date",
                    "parties.name as party_name",
                    "stocks.bill_number as bill_number"
                )->get();

            /* ---------------- FREE PURCHASE ---------------- */
            $purchaseFreeItems = $applyDateFilter(
                StockMovement::where('stock_movements.type', 'purchase')
                    ->leftJoin('stocks', 'stocks.id', '=', 'stock_movements.stock_id')
                    ->leftJoin('parties', 'parties.id', '=', 'stocks.party_id')
                    ->where('stock_movements.product_id', $request->product_id)
            )->select(
                    "stock_movements.id as id",
                    "stock_movements.id as product_id",
                    "stock_movements.stock_id as stock_id",
                    "stock_movements.tracker_id as tracker_id",
                    "stock_movements.created_at as date",
                    DB::raw("(stock_movements.quantity / $factor) as purchase_qty"),
                    DB::raw("0 as sale_qty"),
                    DB::raw("0 as purchase_return_qty"),
                    DB::raw("0 as sale_return_qty"),
                    "stocks.invoice_date_bs as invoice_date",
                    "parties.name as party_name",
                    "stocks.bill_number as bill_number"
                )->get();

            /* ---------------- SALE ---------------- */
            $saleRegularItems = $applyDateFilter(
                StockTransaction::where('stock_transactions.type', 'sale')
                    ->leftJoin('stocks', 'stocks.id', '=', 'stock_transactions.stock_id')
                    ->leftJoin('parties', 'parties.id', '=', 'stocks.party_id')
                    ->where('product_id', $request->product_id)
            )->select(
                    "stock_transactions.id as id",
                    "stock_transactions.id as product_id",
                    "stock_transactions.stock_id as stock_id",
                    "stock_transactions.tracker_id as tracker_id",
                    "stock_transactions.created_at as date",
                    DB::raw("0 as purchase_qty"),
                    DB::raw("(stock_transactions.quantity / $factor) as sale_qty"),
                    DB::raw("0 as purchase_return_qty"),
                    DB::raw("0 as sale_return_qty"),
                    "stocks.invoice_date_bs as invoice_date",
                    "parties.name as party_name",
                    "stocks.bill_number as bill_number"
                )->get();

            $saleFreeItems = $applyDateFilter(
                StockMovement::where('stock_movements.type', 'sale')
                    ->leftJoin('stocks', 'stocks.id', '=', 'stock_movements.stock_id')
                    ->leftJoin('parties', 'parties.id', '=', 'stocks.party_id')
                    ->where('stock_movements.product_id', $request->product_id)
            )->select(
                    "stock_movements.id as id",
                    "stock_movements.id as product_id",
                    "stock_movements.stock_id as stock_id",
                    "stock_movements.tracker_id as tracker_id",
                    "stock_movements.created_at as date",
                    DB::raw("0 as purchase_qty"),
                    DB::raw("(stock_movements.quantity / $factor) as sale_qty"),
                    DB::raw("0 as purchase_return_qty"),
                    DB::raw("0 as sale_return_qty"),
                    "stocks.invoice_date_bs as invoice_date",
                    "parties.name as party_name",
                    "stocks.bill_number as bill_number"
                )->get();

            /* ---------------- PURCHASE RETURN ---------------- */
            $purchaseReturnRegularItems = $applyDateFilter(
                StockTransaction::where('stock_transactions.type', 'purchase_return')
                    ->leftJoin('stocks', 'stocks.id', '=', 'stock_transactions.stock_id')
                    ->leftJoin('parties', 'parties.id', '=', 'stocks.party_id')
                    ->where('product_id', $request->product_id)
            )->select(
                    "stock_transactions.id as id",
                    "stock_transactions.id as product_id",
                    "stock_transactions.stock_id as stock_id",
                    "stock_transactions.tracker_id as tracker_id",
                    "stock_transactions.created_at as date",
                    DB::raw("0 as purchase_qty"),
                    DB::raw("0 as sale_qty"),
                    DB::raw("(stock_transactions.quantity / $factor) as purchase_return_qty"),
                    DB::raw("0 as sale_return_qty"),
                    "stocks.invoice_date_bs as invoice_date",
                    "parties.name as party_name",
                    "stocks.bill_number as bill_number"
                )->get();

            $purchaseReturnFreeItems = $applyDateFilter(
                StockMovement::where('stock_movements.type', 'purchase_return')
                    ->leftJoin('stocks', 'stocks.id', '=', 'stock_movements.stock_id')
                    ->leftJoin('parties', 'parties.id', '=', 'stocks.party_id')
                    ->where('stock_movements.product_id', $request->product_id)
            )->select(
                    "stock_movements.id as id",
                    "stock_movements.id as product_id",
                    "stock_movements.stock_id as stock_id",
                    "stock_movements.tracker_id as tracker_id",
                    "stock_movements.created_at as date",
                    DB::raw("0 as purchase_qty"),
                    DB::raw("0 as sale_qty"),
                    DB::raw("(stock_movements.quantity / $factor) as purchase_return_qty"),
                    DB::raw("0 as sale_return_qty"),
                    "stocks.invoice_date_bs as invoice_date",
                    "parties.name as party_name",
                    "stocks.bill_number as bill_number"
                )->get();


            $saleReturnRegularItems = $applyDateFilter(
                StockTransaction::where('stock_transactions.type', 'sales_return')
                    ->leftJoin('stocks', 'stocks.id', '=', 'stock_transactions.stock_id')
                    ->leftJoin('parties', 'parties.id', '=', 'stocks.party_id')
                    ->where('product_id', $request->product_id)
            )->select(
                    "stock_transactions.id as id",
                    "stock_transactions.id as product_id",
                    "stock_transactions.stock_id as stock_id",
                    "stock_transactions.tracker_id as tracker_id",
                    "stock_transactions.created_at as date",
                    DB::raw("0 as purchase_qty"),
                    DB::raw("0 as sale_qty"),
                    DB::raw("0 as purchase_return_qty"),
                    DB::raw("(stock_transactions.quantity / $factor) as sale_return_qty"),
                    "stocks.invoice_date_bs as invoice_date",
                    "parties.name as party_name",
                    "stocks.bill_number as bill_number"
                )->get();

            $saleReturnFreeItems = $applyDateFilter(
                StockMovement::where('stock_movements.type', 'sales_return')
                    ->leftJoin('stocks', 'stocks.id', '=', 'stock_movements.stock_id')
                    ->leftJoin('parties', 'parties.id', '=', 'stocks.party_id')
                    ->where('stock_movements.product_id', $request->product_id)
            )->select(
                    "stock_movements.id as id",
                    "stock_movements.id as product_id",
                    "stock_movements.stock_id as stock_id",
                    "stock_movements.tracker_id as tracker_id",
                    "stock_movements.created_at as date",
                    DB::raw("0 as purchase_qty"),
                    DB::raw("0 as sale_qty"),
                    DB::raw("0 as purchase_return_qty"),
                    DB::raw("(stock_movements.quantity / $factor) as sale_return_qty"),
                    "stocks.invoice_date_bs as invoice_date",
                    "parties.name as party_name",
                    "stocks.bill_number as bill_number"
                )->get();


            $adjustmentItemsIn = StockMovement::where('stock_movements.type', 'stock_adjustment')
                ->leftJoin('stocks', 'stocks.id', '=', 'stock_movements.stock_id')
                ->where('stock_movements.product_id', $request->product_id)
                ->where('stock_movements.stock_type', 'add')
                ->select(
                    "stock_movements.id as id",
                    "stock_movements.id as product_id",
                    "stock_movements.stock_id as stock_id",
                    "stock_movements.tracker_id as tracker_id",
                    "stock_movements.created_at as date",
                    DB::raw("0 as purchase_qty"),
                    DB::raw("0 as sale_qty"),
                    DB::raw("0 as purchase_return_qty"),
                    DB::raw("0 as sale_return_qty"),
                    DB::raw("(stock_movements.quantity / $factor) as adjustment_in"),
                    "stocks.invoice_date_bs as invoice_date",
                    DB::raw("'Stock Adjustment' as party_name"),
                    "stocks.bill_number as bill_number"
                )
                ->get();

            $adjustmentItemsOut = StockMovement::where('stock_movements.type', 'stock_adjustment')
                ->leftJoin('stocks', 'stocks.id', '=', 'stock_movements.stock_id')
                ->where('stock_movements.product_id', $request->product_id)
                ->where('stock_movements.stock_type', 'subtract')
                ->select(
                    "stock_movements.id as id",
                    "stock_movements.id as product_id",
                    "stock_movements.stock_id as stock_id",
                    "stock_movements.tracker_id as tracker_id",
                    "stock_movements.created_at as date",
                    DB::raw("0 as purchase_qty"),
                    DB::raw("0 as sale_qty"),
                    DB::raw("0 as purchase_return_qty"),
                    DB::raw("0 as sale_return_qty"),
                    DB::raw("(stock_movements.quantity / $factor) as adjustment_out"),
                    "stocks.invoice_date_bs as invoice_date",
                    DB::raw("'Stock Adjustment' as party_name"),
                    "stocks.bill_number as bill_number"
                )
                ->get();


            $transactions = collect()
                ->merge($openingItems)
                ->merge($purchaseRegularItems)
                ->merge($purchaseFreeItems)
                ->merge($saleRegularItems)
                ->merge($saleFreeItems)
                ->merge($purchaseReturnRegularItems)
                ->merge($purchaseReturnFreeItems)
                ->merge($saleReturnRegularItems)
                ->merge($saleReturnFreeItems)
                ->merge($adjustmentItemsIn)
                ->merge($adjustmentItemsOut)
                ->sortBy('created_at')
                ->values();


            $grouped = $transactions->groupBy(function ($t) {

                $type = null;

                if (!empty($t->sale_qty)) {
                    $type = 'sale';
                } elseif (!empty($t->sale_return_qty)) {
                    $type = 'sale_return';
                } elseif (!empty($t->purchase_return_qty)) {
                    $type = 'purchase_return';
                } elseif (!empty($t->purchase_qty)) {
                    $type = 'purchase';
                } elseif (!empty($t->opening_qty)) {
                    $type = 'opening';
                } elseif (!empty($t->adjustment_in)) {
                    $type = 'adjustment_in';
                } elseif (!empty($t->adjustment_out)) {
                    $type = 'adjustment_out';
                }

                return $t->stock_id . '_' . $type;
            });


            $final = $grouped->map(function ($items) use ($primaryUnit) {

                $first = $items->first();


                return (object) [
                    'id' => $first->id,
                    'product_id' => $first->product_id,
                    'date' => \Carbon\Carbon::parse($first->date)->format('Y-m-d'),
                    'party_name' => $first->party_name,
                    'bill_number' => $first->bill_number,

                    'opening_qty' => $items->sum('opening_qty'),
                    'purchase_qty' => $items->sum('purchase_qty'),
                    'sale_qty' => $items->sum('sale_qty'),
                    'sale_return_qty' => $items->sum('sale_return_qty'),
                    'purchase_return_qty' => $items->sum('purchase_return_qty'),
                    'adjustment_qty' =>
                        $items->sum('adjustment_in') - $items->sum('adjustment_out'),

                ];
            })
                ->sortBy('date')
                ->values();


            $balance = 0;

            $final = $final->map(function ($t) use (&$balance, $primaryUnit) {

                $balance += $t->opening_qty;
                $balance += $t->purchase_qty;
                $balance += $t->sale_return_qty;

                $balance -= $t->sale_qty;
                $balance -= $t->purchase_return_qty;
                $balance += $t->adjustment_qty;

                $t->total_quantity = number_format($balance, 2, '.', '');
                $t->primary_unit_name = $primaryUnit->name ?? 'N/A';

                return $t;
            });

            return response()->json($final);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found !!'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unexpected error !',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    public function stockSummaryReport(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'method_type' => 'nullable|string|in:fifo,average',

                'product_id' => 'nullable|integer|exists:products,id',
                'category_id' => 'nullable|integer|exists:product_categories,id',
                'sub_category_id' => 'nullable|integer|exists:product_sub_categories,id',
                'brand_id' => 'nullable|integer|exists:brands,id',
                'vat_type' => 'nullable|in:0,1',
                'product_type_id' => 'nullable|integer|exists:product_types,id',
                'location_id' => 'nullable|integer|exists:locations,id',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $fromDate = $request->from_date;
            $toDate = $request->to_date;
            $companyId = $request->company_id;
            $branchId = $request->branch_id;

            $primaryRow = DB::table('product_lists')
                ->where('is_primary', 1)
                ->first();

            $primaryUnit = DB::table('measure_units')
                ->where('id', $primaryRow->measure_unit_id ?? null)
                ->first();

            $factor = $primaryUnit->quantity ?? 1;

            $applyDateFilter = function ($query) use ($fromDate, $toDate) {
                return $query
                    ->when($fromDate && $toDate, fn($q) => $q->whereBetween('stocks.invoice_date_bs', [$fromDate, $toDate]))
                    ->when($fromDate && !$toDate, fn($q) => $q->where('stocks.invoice_date_bs', '>=', $fromDate))
                    ->when(!$fromDate && $toDate, fn($q) => $q->where('stocks.invoice_date_bs', '<=', $toDate));
            };

            $applyFilters = function ($query, $table) use ($request) {
                return $query
                    ->leftJoin('products', 'products.id', '=', "$table.product_id")
                    ->when($request->product_id, fn($q) => $q->where("$table.product_id", $request->product_id))
                    ->when($request->category_id, fn($q) => $q->where('products.category_id', $request->category_id))
                    ->when($request->sub_category_id, fn($q) => $q->where('products.sub_category_id', $request->sub_category_id))
                    ->when($request->brand_id, fn($q) => $q->where('products.brand_id', $request->brand_id))
                    ->when(!is_null($request->vat_type), fn($q) => $q->where('products.is_vatable', $request->vat_type))
                    ->when($request->product_type_id, fn($q) => $q->where('products.product_type_id', $request->product_type_id));
                // ->when($request->location_id, fn($q) => $q->where('stocks.location_id', $request->location_id));
            };

            // ----------------------------
            // STOCK DATA (UNCHANGED - Logic Kept Same)
            // ----------------------------
            $opening = $applyFilters(
                StockProduct::where('stock_products.type', 'opening_stock')
                    ->where('stock_products.branch_id', $branchId)
                    ->whereNull('stock_products.deleted_at'),
                'stock_products'
            )
                ->select('stock_products.product_id', DB::raw("SUM(quantity/$factor) as opening_qty"))
                ->groupBy('stock_products.product_id')
                ->get();

            $purchase = $applyDateFilter(
                $applyFilters(
                    StockProduct::where('stock_products.type', 'purchase')
                        ->whereNull('stock_products.deleted_at')
                        ->where('stock_products.branch_id', $branchId)
                        ->leftJoin('stocks', 'stocks.id', '=', 'stock_products.stock_id'),
                    'stock_products'
                )
            )->select('stock_products.product_id', DB::raw("SUM(quantity/$factor) as qty"))
                ->groupBy('stock_products.product_id')->get();

            $purchaseFree = $applyDateFilter(
                $applyFilters(
                    StockMovement::where('stock_movements.type', 'purchase')
                        ->whereNull('stock_movements.deleted_at')
                        ->where('stock_movements.branch_id', $branchId)
                        ->leftJoin('stocks', 'stocks.id', '=', 'stock_movements.stock_id'),
                    'stock_movements'
                )
            )->select('stock_movements.product_id', DB::raw("SUM(quantity/$factor) as qty"))
                ->groupBy('stock_movements.product_id')->get();

            $sale = $applyDateFilter(
                $applyFilters(
                    StockTransaction::where('stock_transactions.type', 'sale')
                        ->whereNull('stock_transactions.deleted_at')
                        ->where('stock_transactions.branch_id', $branchId)
                        ->leftJoin('stocks', 'stocks.id', '=', 'stock_transactions.stock_id'),
                    'stock_transactions'
                )
            )->select('stock_transactions.product_id', DB::raw("SUM(quantity/$factor) as qty"))
                ->groupBy('stock_transactions.product_id')->get();

            $saleFree = $applyDateFilter(
                $applyFilters(
                    StockMovement::where('stock_movements.type', 'sale')
                        ->whereNull('stock_movements.deleted_at')
                        ->where('stock_movements.branch_id', $branchId)
                        ->leftJoin('stocks', 'stocks.id', '=', 'stock_movements.stock_id'),
                    'stock_movements'
                )
            )->select('stock_movements.product_id', DB::raw("SUM(quantity/$factor) as qty"))
                ->groupBy('stock_movements.product_id')->get();

            $purchaseReturn = $applyDateFilter(
                $applyFilters(
                    StockTransaction::where('stock_transactions.type', 'purchase_return')
                        ->whereNull('stock_transactions.deleted_at')
                        ->where('stock_transactions.branch_id', $branchId)
                        ->leftJoin('stocks', 'stocks.id', '=', 'stock_transactions.stock_id'),
                    'stock_transactions'
                )
            )->select('stock_transactions.product_id', DB::raw("SUM(quantity/$factor) as qty"))
                ->groupBy('stock_transactions.product_id')->get();

            $purchaseReturnFree = $applyDateFilter(
                $applyFilters(
                    StockMovement::where('stock_movements.type', 'purchase_return')
                        ->whereNull('stock_movements.deleted_at')
                        ->where('stock_movements.branch_id', $branchId)
                        ->leftJoin('stocks', 'stocks.id', '=', 'stock_movements.stock_id'),
                    'stock_movements'
                )
            )->select('stock_movements.product_id', DB::raw("SUM(quantity/$factor) as qty"))
                ->groupBy('stock_movements.product_id')->get();

            $saleReturn = $applyDateFilter(
                $applyFilters(
                    StockTransaction::where('stock_transactions.type', 'sales_return')
                        ->whereNull('stock_transactions.deleted_at')
                        ->where('stock_transactions.branch_id', $branchId)
                        ->leftJoin('stocks', 'stocks.id', '=', 'stock_transactions.stock_id'),
                    'stock_transactions'
                )
            )->select('stock_transactions.product_id', DB::raw("SUM(quantity/$factor) as qty"))
                ->groupBy('stock_transactions.product_id')->get();

            $saleReturnFree = $applyDateFilter(
                $applyFilters(
                    StockMovement::where('stock_movements.type', 'sales_return')
                        ->whereNull('stock_movements.deleted_at')
                        ->where('stock_movements.branch_id', $branchId)
                        ->leftJoin('stocks', 'stocks.id', '=', 'stock_movements.stock_id'),
                    'stock_movements'
                )
            )->select('stock_movements.product_id', DB::raw("SUM(quantity/$factor) as qty"))
                ->groupBy('stock_movements.product_id')->get();

            // Average calculations (unchanged)
            $getAvg = function ($table, $type, $factor) {

                return DB::table("$table as t")
                    ->select(
                        't.product_id',

                        DB::raw("SUM(t.quantity / {$factor}) as total_qty"),

                        DB::raw("SUM(t.amount) as total_amount")
                    )
                    ->where('t.type', $type)
                    ->whereNull('t.deleted_at')
                    ->groupBy('t.product_id')
                    ->get()
                    ->map(function ($row) {

                        $qty = $row->total_qty;
                        $amount = $row->total_amount;

                        return [
                            'product_id' => $row->product_id,

                            // weighted average rate
                            'price' => $qty > 0
                                ? ($amount / $qty)
                                : 0,

                            'amount' => $amount,
                        ];
                    });
            };

            $purchaseAvg = $getAvg('stock_products', 'purchase', $factor)
                ->merge($getAvg('stock_movements', 'purchase', $factor));

            $saleAvg = $getAvg('stock_transactions', 'sale', $factor)
                ->merge($getAvg('stock_movements', 'sale', $factor));

            $purchaseReturnAvg = $getAvg('stock_transactions', 'purchase_return', $factor)
                ->merge($getAvg('stock_movements', 'purchase_return', $factor));

            $saleReturnAvg = $getAvg('stock_transactions', 'sales_return', $factor)
                ->merge($getAvg('stock_movements', 'sales_return', $factor));

            $groupAvg = function ($data) {
                return collect($data)
                    ->groupBy('product_id')
                    ->map(fn($items) => [
                        'price' => collect($items)->avg('price'),
                        'amount' => collect($items)->avg('amount'),
                    ]);
            };

            $purchaseAvg = $groupAvg($purchaseAvg);
            $saleAvg = $groupAvg($saleAvg);
            $purchaseReturnAvg = $groupAvg($purchaseReturnAvg);
            $saleReturnAvg = $groupAvg($saleReturnAvg);

            $result = [];

            $merge = function ($data, $field) use (&$result) {
                foreach ($data as $item) {
                    $pid = $item->product_id;

                    if (!isset($result[$pid])) {
                        $result[$pid] = [
                            'product_id' => $pid,
                            'opening_qty' => 0,
                            'purchase_qty' => 0,
                            'sale_qty' => 0,
                            'purchase_return_qty' => 0,
                            'sale_return_qty' => 0,
                        ];
                    }

                    $result[$pid][$field] += (float) ($item->qty ?? $item->$field ?? 0);
                }
            };

            $merge($opening, 'opening_qty');
            $merge($purchase, 'purchase_qty');
            $merge($purchaseFree, 'purchase_qty');
            $merge($sale, 'sale_qty');
            $merge($saleFree, 'sale_qty');
            $merge($purchaseReturn, 'purchase_return_qty');
            $merge($purchaseReturnFree, 'purchase_return_qty');
            $merge($saleReturn, 'sale_return_qty');
            $merge($saleReturnFree, 'sale_return_qty');

            // ==================== NEW: Fetch Product Names ====================
            $productIds = array_keys($result);

            $productDetails = [];
            if (!empty($productIds)) {
                $productDetails = DB::table('products')
                    ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
                    ->leftJoin('product_sub_categories', 'product_sub_categories.id', '=', 'products.sub_category_id')
                    ->leftJoin('product_types', 'product_types.id', '=', 'products.product_type_id')
                    ->leftJoin('locations', 'locations.id', '=', 'products.location_id')
                    ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                    ->leftJoin('product_lists', function ($join) {
                        $join->on('product_lists.product_id', '=', 'products.id')
                            ->where('product_lists.is_primary', 1);
                    })
                    ->leftJoin('measure_units', 'measure_units.id', '=', 'product_lists.measure_unit_id')
                    ->whereIn('products.id', $productIds)
                    ->select([
                        'products.id',
                        'products.product_code as product_id',
                        'products.name as product_name',
                        'product_categories.name as category_name',
                        'product_sub_categories.name as sub_category_name',
                        'product_types.name as product_type_name',
                        'locations.name as location_name',
                        'brands.name as brand_name',
                        'measure_units.name as primary_unit_name'
                    ])
                    ->get()
                    ->keyBy('id');
            }


            $fifoCalc = function ($productId) use ($companyId, $branchId) {
                return $this->productReportService::fifoRate($productId, $companyId, $branchId);
            };

            foreach ($result as &$row) {

                $row['closing_qty'] =
                    $row['opening_qty']
                    + $row['purchase_qty']
                    + $row['sale_return_qty']
                    - $row['sale_qty']
                    - $row['purchase_return_qty'];

                $pid = $row['product_id'];

                // Add Names (Default to empty string if not found)
                $details = $productDetails[$pid] ?? (object) [
                    'product_name' => '',
                    'category_name' => '',
                    'sub_category_name' => '',
                    'brand_name' => '',
                    'location_name' => '',
                    'product_type_name' => '',
                    'product_id' => '',
                    'primary_unit_name' => '',
                ];

                $row['product_name'] = $details->product_name;
                $row['category_name'] = $details->category_name;
                $row['sub_category_name'] = $details->sub_category_name;
                $row['brand_name'] = $details->brand_name;
                $row['location_name'] = $details->location_name;
                $row['product_type_name'] = $details->product_type_name;
                $row['product_id'] = $details->product_id;
                $row['primary_unit_name'] = $details->primary_unit_name;

                // Your original FIFO logic (unchanged)

                if ($request->method_type === 'fifo') {
                    $fifo = $fifoCalc($pid);

                    $row['purchase_return_rate'] = number_format($fifo['fifo_rate'], 2);
                    $row['purchase_return_amount'] = number_format($fifo['fifo_amount'], 2);

                    $row['sale_rate'] = number_format($fifo['fifo_rate'], 2);
                    $row['sale_amount'] = number_format($fifo['fifo_amount'], 2);

                    $row['purchase_rate'] = number_format(($purchaseAvg[$pid]['price'] ?? 0) * $factor, 2, '.', '');
                    $row['purchase_amount'] = number_format(($purchaseAvg[$pid]['amount'] ?? 0) * $factor, 2, '.', '');

                    $row['sale_return_rate'] = number_format(($saleReturnAvg[$pid]['price'] ?? 0) * $factor, 2, '.', '');
                    $row['sale_return_amount'] = number_format(($saleReturnAvg[$pid]['amount'] ?? 0) * $factor, 2, '.', '');
                }

                // Your original AVERAGE logic (unchanged)
                if ($request->method_type === 'average') {
                    $row['purchase_rate'] = number_format(($purchaseAvg[$pid]['price'] ?? 0) * $factor, 2);
                    $row['purchase_amount'] = number_format(($purchaseAvg[$pid]['amount'] ?? 0) * $factor, 2);

                    $row['sale_rate'] = number_format(($saleAvg[$pid]['price'] ?? 0) * $factor, 2);
                    $row['sale_amount'] = number_format(($saleAvg[$pid]['amount'] ?? 0) * $factor, 2);

                    $row['purchase_return_rate'] = number_format(($purchaseReturnAvg[$pid]['price'] ?? 0) * $factor, 2);
                    $row['purchase_return_amount'] = number_format(($purchaseReturnAvg[$pid]['amount'] ?? 0) * $factor, 2);

                    $row['sale_return_rate'] = number_format(($saleReturnAvg[$pid]['price'] ?? 0) * $factor, 2);
                    $row['sale_return_amount'] = number_format(($saleReturnAvg[$pid]['amount'] ?? 0) * $factor, 2);
                }

                // Your original number formatting loop (unchanged)
                foreach ($row as $k => $v) {
                    if (!is_array($v)) {
                        if (is_numeric($v)) {
                            $row[$k] = number_format($v, 2, '.', '');
                        } else {
                            $row[$k] = $v ?? '';
                        }
                    }
                }
            }

            return response()->json(array_values($result));

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Item Not Found !',
                'message' => $e->getMessage()
            ], 500);
        } catch (QueryException $e) {
            return response()->json([
                'error' => 'Database error occurred !!',
                'message' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function cbmsVatReturnListDetails(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|string|in:sales,sales_return,purchases,purchase_return',
                'month' => 'required|numeric',
                'year' => 'required|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if ($request->type === "purchases") {

                $items = Purchase::select("purchases.id", "purchases.invoice_date_bs AS date", "purchases.sub_total_before_discount as total_amount", "purchases.taxable_amount as taxable_amount", "purchases.purchase_bill_number as bill_number", "purchases.non_taxable_amount as non_taxable_amount", "purchases.customer_id", DB::raw('COALESCE(ROUND(purchases.vat_percent,2),0) as vat_amount'))->with(relations: 'customer:id,party_name,pan_number')->orderBy('id', 'asc');

                if ($request->has('month')) {
                    $items->whereRaw(' CAST(SUBSTRING(invoice_date_bs, 6, 2) AS UNSIGNED) = ?', $request->input('month'));
                }
                if ($request->has('year')) {
                    $items->whereRaw('SUBSTRING(invoice_date_bs, 1, 4) = ?', $request->input('year'));
                }
                $items = $items->get();

            } else if ($request->type === "sales") {
                $items = Sale::select("sales.id", "sales.invoice_date_bs AS date", "sales.sub_total_before_discount as total_amount", "sales.taxable_amount as taxable_amount", "sales.invoice_number as bill_number", "sales.non_taxable_amount as non_taxable_amount", "sales.customer_id", DB::raw('COALESCE(ROUND(sales.taxable_amount * .13,2),0) as vat_amount'))->with(relations: 'customer:id,party_name,pan_number')->orderBy('id', 'asc');

                if ($request->has('month')) {
                    $items->whereRaw(' CAST(SUBSTRING(invoice_date_bs, 6, 2) AS UNSIGNED) = ?', $request->input('month'));
                }
                if ($request->has('year')) {
                    $items->whereRaw('SUBSTRING(invoice_date_bs, 1, 4) = ?', $request->input('year'));
                }

                $items = $items->get();

            } else if ($request->type === "purchase_return") {
                $items = DB::table('purchase_product_returns as ppr')
                    ->join('purchase_returns as pr', 'ppr.purchase_return_id', '=', 'pr.id')
                    ->join('customers as c', 'pr.customer_id', '=', 'c.id')
                    ->select([
                        'pr.invoice_date_bs as date',
                        'pr.purchase_bill_number as bill_number',
                        'c.party_name as supplier_name',
                        'c.pan_number as supplier_pan',
                        'ppr.product_name as product_service_name',
                        'ppr.product_id as product_id',
                        DB::raw('SUM(ppr.quantity) as product_quantity'),
                        DB::raw('SUM(ppr.amount) as total_purchase'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 0 THEN ppr.amount ELSE 0 END) as non_taxable'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 1 THEN ppr.amount ELSE 0 END) as taxable'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 1 THEN ROUND(ppr.amount * .13,2) ELSE 0 END) as vat_amount'),
                    ])
                    ->when(isset($request->month) && isset($request->year), function ($query) use ($request) {
                        $query->whereRaw(' CAST(SUBSTRING(invoice_date_bs, 6, 2) AS UNSIGNED) = ?', $request->input('month'))->whereRaw('SUBSTRING(invoice_date_bs, 1, 4) = ?', $request->input('year'));
                    })
                    ->where('ppr.company_id', $request->company_id)
                    ->groupBy([
                        'pr.invoice_date_bs',
                        'pr.purchase_bill_number',
                        'c.party_name',
                        'c.pan_number',
                        'ppr.product_name',
                        'ppr.product_id',
                    ])
                    ->orderBy('pr.invoice_date_bs')
                    ->get();

                $items->each(function ($item) {
                    $product = Product::findOrFail($item->product_id);
                    $item->primary_unit_name = $product->getPrimaryMeasureUnitAttribute()->name;
                });

            } else if ($request->type === "sales_return") {
                $items = DB::table('sales_return_products as ppr')
                    ->join('sales_returns as pr', 'ppr.sales_return_id', '=', 'pr.id')
                    ->join('customers as c', 'pr.customer_id', '=', 'c.id')
                    ->select([
                        'pr.invoice_date_bs as date',
                        'pr.invoice_number as bill_number',
                        'c.party_name as supplier_name',
                        'c.pan_number as supplier_pan',
                        'ppr.product_name as product_service_name',
                        'ppr.product_id as product_id',
                        DB::raw('SUM(ppr.quantity) as product_quantity'),
                        DB::raw('SUM(ppr.price) as total_sales'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 0 THEN ppr.price ELSE 0 END) as non_taxable'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 1 THEN ppr.price ELSE 0 END) as taxable'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 1 THEN ROUND(ppr.price * .13,2) ELSE 0 END) as vat_amount'),
                    ])->where('ppr.company_id', $request->company_id)
                    ->when(isset($request->month) && isset($request->year), function ($query) use ($request) {
                        $query->whereRaw(' CAST(SUBSTRING(invoice_date_bs, 6, 2) AS UNSIGNED) = ?', $request->input('month'))->whereRaw('SUBSTRING(invoice_date_bs, 1, 4) = ?', $request->input('year'));
                    })
                    ->groupBy([
                        'pr.invoice_date_bs',
                        'pr.invoice_number',
                        'c.party_name',
                        'c.pan_number',
                        'ppr.product_name',
                        'ppr.product_id',
                    ])
                    ->orderBy('pr.invoice_date_bs')
                    ->get();
                $items->each(function ($item) {
                    $product = Product::findOrFail($item->product_id);
                    $item->primary_unit_name = $product->getPrimaryMeasureUnitAttribute()->name;
                });
            }
            return response()->json($items);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function vatReturnDataListDetails(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'month' => 'required|numeric',
                'year' => 'required|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
                return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
            }

            $applyFilters = function ($query) use ($request) {
                $query->when(isset($request->month) && isset($request->year), function ($query1) use ($request) {
                    $query1->whereRaw(' CAST(SUBSTRING(invoice_date_bs, 6, 2) AS UNSIGNED) = ?', $request->input('month'))->whereRaw('SUBSTRING(invoice_date_bs, 1, 4) = ?', $request->input('year'));
                });
            };

            $sale_taxable_amount = Sale::tap($applyFilters)->sum('taxable_amount');

            $purchase_taxable_amount = Purchase::tap($applyFilters)->sum('taxable_amount');

            $sale_return_amount = SalesReturn::tap($applyFilters)->sum('taxable_amount');

            $report = [
                'sales' => [
                    'vatable' => round($sale_taxable_amount, 2),
                    'non_vatable' => round(Sale::tap($applyFilters)->sum('non_taxable_amount'), 2),
                    'export' => 0,
                    'vat' => round($sale_taxable_amount * 0.13, 2),
                ],
                'purchase' => [
                    'vatable' => round($purchase_taxable_amount, 2),
                    'non_vatable' => round(Purchase::tap($applyFilters)->sum('non_taxable_amount'), 2),
                    'vatable_import' => round(0 * 0.13, 2),
                    'non_vatable_import' => round(0 * 0.13, 2),
                    'vat' => round($purchase_taxable_amount * 0.13, 2),
                ],
                'bill' => [
                    'purchase' => Purchase::tap($applyFilters)->count('id'),
                    'purchase_return' => PurchaseReturn::tap($applyFilters)->count('id'),
                    'sale_return' => SalesReturn::tap($applyFilters)->count('id'),
                    'sale_return_advice' => round(0 * 0.13, 2),
                    'purchase_return_advice' => round(0 * 0.13, 2),
                    'sale' => Sale::tap($applyFilters)->count('id'),
                ],
                'other' => [
                    'purchase_return_vat' => round(0 * 0.13, 2),
                    'sale_return_vat' => round($sale_return_amount * 0.13, 2),
                    'customer_return_vat' => round(0 * 0.13, 2),
                ],
                'net_payable_amount' => round(($sale_taxable_amount * 0.13) - ($purchase_taxable_amount * 0.13) - ($sale_return_amount * 0.13), 2),
            ];
            Helper::applyCache($request->fullUrlWithQuery($request->all()), $report);
            return response()->json($report);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function purchaseSalesBookListDetail(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'from_date' => 'nullable',
                'to_date' => 'nullable',
                'type' => 'required|in:sales,sales_return,purchase,purchase_return',
                'party_id' => 'nullable|exists:parties,id',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            // $cacheKey = 'purchase_sales_book_' . md5(json_encode([
            //     'company_id' => $request->company_id,
            //     'type' => $request->type,
            //     'from_date' => $request->from_date,
            //     'to_date' => $request->to_date,
            //     'party_id' => $request->party_id,
            // ]));

            // if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
            //     return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
            // }

            $fromDate = $request->input('from_date');
            $toDate = $request->input('to_date');
            $partyId = $request->input('party_id');

            // Helper function to apply filters
            $applyFilters = function ($query) use ($fromDate, $toDate, $partyId, $request) {

                $query->where('stocks.company_id', $request->company_id);

                $query->when($fromDate, function ($q) use ($fromDate) {
                    $q->where('stocks.invoice_date_bs', '>=', $fromDate);
                });

                $query->when($toDate, function ($q) use ($toDate) {
                    $q->where('stocks.invoice_date_bs', '<=', $toDate);
                });

                $query->when($partyId, function ($q) use ($partyId) {
                    $q->where('stocks.party_id', $partyId);
                });
            };


            $items = match ($request->type) {
                'purchase' => Stock::where('type', 'purchase')->where('branch_id', $request->branch_id)->whereNull('stocks.deleted_at')->selectRaw('invoice_date_bs as tr_date,
                            bill_number as bill_number,
                            sub_total_before_discount as before_vat_amt,
                            FORMAT(IFNULL(taxable_amount * 0.13, 0), 2) as vat_amount,
                            total_amount,
                            parties.pan_number as pan,
                            taxable_amount as taxable_sales,
                            non_taxable_amount as non_taxable_sales,
                           ref_bill_number as voucher_number,
                            parties.name as party_name,
        "Purchase" as type')->leftJoin("parties", "parties.id", "=", "stocks.party_id")
                    ->tap($applyFilters)
                    ->get(),
                'purchase_return' => Stock::where('type', 'purchase_return')->where('branch_id', $request->branch_id)->whereNull('stocks.deleted_at')->selectRaw('
                                        invoice_date_bs as tr_date,
                                        bill_number as bill_number,
                                        sub_total_before_discount as before_vat_amt,
                                        FORMAT(IFNULL(taxable_amount * 0.13, 0), 2) as vat_amount,
                                        total_amount AS total_amount,
                                        parties.pan_number as pan,
                                        taxable_amount as taxable_sales,
                                        non_taxable_amount as non_taxable_sales,
                                        ref_bill_number as voucher_number,
                                        parties.name as party_name,
                                        "Purchase Return" as type
                                ')->leftJoin("parties", "parties.id", "=", "stocks.party_id")
                    ->get(),
                'sales' => Stock::where('type', 'sale')->where('branch_id', $request->branch_id)->whereNull('stocks.deleted_at')->selectRaw('
        invoice_date_bs as tr_date,
        bill_number as bill_number,
        sub_total_before_discount as before_vat_amt,
        FORMAT(IFNULL(taxable_amount * 0.13, 0), 2) as vat_amount, -- Adjust VAT rate as needed
        total_amount,
        parties.pan_number as pan,
        taxable_amount as taxable_sales,
        non_taxable_amount as non_taxable_sales,
       ref_bill_number as voucher_number,
        parties.name as party_name,
        "Sales" as type
    ')->leftJoin("parties", "parties.id", "=", "stocks.party_id")
                    ->get(),
                'sales_return' => Stock::where('type', 'sales_return')->where('branch_id', $request->branch_id)->whereNull('stocks.deleted_at')->selectRaw('
        invoice_date_bs as tr_date,
        bill_number as bill_number,
        sub_total_before_discount as before_vat_amt,
        FORMAT(IFNULL(taxable_amount * 0.13, 0), 2) as vat_amount,
        total_amount as total_amount,
        parties.pan_number as pan,
        taxable_amount as taxable_sales,
        non_taxable_amount as non_taxable_sales,
        ref_bill_number as voucher_number,
        parties.name as party_name,
        "Sales Return" as type
    ')->leftJoin("parties", "parties.id", "=", "stocks.party_id")
                    ->whereNull('stocks.deleted_at')->tap($applyFilters)
                    ->where("stocks.company_id", $request->company_id)
                    ->get(),
            };

            // Merge all collections
            $report = collect()
                ->merge($items)
                ->sortBy([
                    ['tr_date', 'asc'],
                    ['bill_number', 'asc'],
                ])
                ->values(); // Re-index

            // Helper::applyCache($request->fullUrlWithQuery($request->all()), $report);
            return response()->json($report);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Item not found !!',
                "message" => $e->getMessage()
            ], 404);

        } catch (QueryException $e) {

            return response()->json([
                'error' => 'Database error occurred !!',
                'message' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {

            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function grossProfitRatioListDetails(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'from_date' => 'required',
                'to_date' => 'required',
                'type' => 'required|string|in:list,download',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if ($request->type === "list") {

                if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
                    return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
                }
                $items = ProductReport::stockRegisterListDetails($request->all());
                $items = $items->paginate(250);
                $items->getCollection()->transform(function ($item) {
                    return $item->append(['opening_quantity', 'opening_rate', 'purchase_detail', 'sale_detail', 'purchase_return_detail', 'sale_return_detail']);
                });
                Helper::applyCache($request->fullUrlWithQuery($request->all()), $items);
                return response()->json($items);
            } else if ($request->type === "download") {
                $user = $request->user();
                $tokenId = $user->currentAccessToken()->id;
                GrossProfitListExportJob::dispatch($tokenId, $request->fullUrlWithQuery($request->all()));
                return response()->json([
                    'message' => 'Gross Profit List export started. You will receive a download link when it is ready.',
                ]);

            }
            return response()->json([]);

        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function grossMarginListDetails(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'from_date' => 'required',
                'to_date' => 'required',
                'type' => 'required|string|in:list,download',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if ($request->type === "list") {
                $items = ProductReport::stockRegisterListDetails($request->all());
                $items = $items->paginate(250);
                $items->getCollection()->transform(function ($item) use ($request) {
                    return [
                        'id' => $item->id,
                        'product_name' => $item->name,
                        'product_unique_id' => $item->product_unique_id,
                        'purchase_rate' => round($item->marginPurchaseDetail($request->all()), 2),
                        'sale_rate' => round($item->marginSaleDetail($request->all()), 2),
                    ];
                });
                //Helper::applyCache($request->fullUrlWithQuery($request->all()), $items);
                return response()->json($items);
            } else if ($request->type === "download") {
                $user = $request->user();
                $tokenId = $user->currentAccessToken()->id;
                GrossProfitListExportJob::dispatch($tokenId, $request->fullUrlWithQuery($request->all()));
                return response()->json([
                    'message' => 'Gross Profit List export started. You will receive a download link when it is ready.',
                ]);

            }
            return response()->json([]);

        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred !'], 500);

        }
    }


    public function voucherList(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_date' => 'nullable|string',
            'to_date' => 'nullable|string',
            'account_head_id' => 'nullable|numeric',
            'account_group_id' => 'nullable|numeric',
            'payment_type' => 'nullable|string|in:cash,bank',
            'voucher_number' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $vouchers = Voucher::query()
            ->with(['voucherDetails.accountHead', 'voucherDetails'])

            ->when($request->filled('voucher_number'), function ($q) use ($request) {
                $q->where('voucher_number', $request->voucher_number);
            })

            ->when($request->filled('from_date') && $request->filled('to_date'), function ($q) use ($request) {
                $q->whereBetween('date_bs', [$request->from_date, $request->to_date]);
            })

            ->when($request->filled('from_date') && !$request->filled('to_date'), function ($q) use ($request) {
                $q->where('date_bs', '>=', $request->from_date);
            })

            ->when(!$request->filled('from_date') && $request->filled('to_date'), function ($q) use ($request) {
                $q->where('date_bs', '<=', $request->to_date);
            })

            ->orderByDesc('date_bs')
            ->paginate(250);
        $transformed = $vouchers->getCollection()->map(function ($voucher) {
            return [
                'voucher_number' => $voucher->voucher_number,
                'date' => $voucher->date,
                'date_bs' => $voucher->date_bs,
                'reference_no' => $voucher->reference_id,
                'reference_type' => $voucher->reference_type,
                'description' => $voucher->description,
                'total_amount' => $voucher->total_amount,
                'is_cancel' => $voucher->is_cancel,
                'company_id' => $voucher->company_id,
                'branch_id' => $voucher->branch_id,

                'details' => $voucher->voucherDetails->map(function ($detail) {
                    return [
                        'id' => $detail->id,
                        'voucher_id' => $detail->voucher_id,
                        'account_head_id' => $detail->account_head_id,
                        'account_head_name' => $detail->accountHead->name ?? null,
                        'narration' => $detail->narration,
                        'particulars' => $detail->accountHead->name ?? null,

                        'debit' => number_format((float) $detail->debit, 2, '.', ''),
                        'credit' => number_format((float) $detail->credit, 2, '.', ''),
                    ];
                })
            ];
        });

        $vouchers->setCollection($transformed);

        return response()->json($vouchers);
    }

    public function voucherReportList(Request $request): JsonResponse
    {
        $vouchers = Voucher::query()
            ->with(['voucherDetails.accountHead'])

            ->when($request->filled('voucher_number'), function ($q) use ($request) {
                $q->where('voucher_number', $request->voucher_number);
            })

            ->when($request->filled('from_date') && $request->filled('to_date'), function ($q) use ($request) {
                $q->whereBetween('date_bs', [$request->from_date, $request->to_date]);
            })

            ->when($request->filled('from_date') && !$request->filled('to_date'), function ($q) use ($request) {
                $q->where('date_bs', '>=', $request->from_date);
            })

            ->when(!$request->filled('from_date') && $request->filled('to_date'), function ($q) use ($request) {
                $q->where('date_bs', '<=', $request->to_date);
            })

            ->orderByDesc('date_bs')
            ->paginate(250);


        $data = $vouchers->getCollection()->map(function ($voucher) {
            return [
                'voucher_number' => $voucher->voucher_number,
                'date' => $voucher->date,
                'date_bs' => $voucher->date_bs,

                'details' => $voucher->voucherDetails->map(function ($detail) {
                    return [
                        'particulars' => $detail->accountHead->name ?? null,
                        'narration' => $detail->narration,
                        'debit' => $detail->debit,
                        'credit' => $detail->credit,
                    ];
                })
            ];
        });


        $vouchers->setCollection($data);

        return response()->json($vouchers);
    }

    public function accountHeadLedger(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date',
                'account_head_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    "message" => "Validation error",
                    "errors" => $validator->errors()
                ], 422);
            }


            $query = VoucherDetails::query()
                ->where('voucher_details.account_head_id', $request->account_head_id)
                ->where('voucher_details.branch_id', $request->branch_id)
                ->whereNull('voucher_details.deleted_at')
                ->join('vouchers', 'vouchers.id', '=', 'voucher_details.voucher_id')
                ->leftJoin('account_heads', 'account_heads.id', '=', 'voucher_details.account_head_id');


            if ($request->filled('from_date') && $request->filled('to_date')) {

                $query->whereBetween('vouchers.date_bs', [
                    $request->from_date,
                    $request->to_date
                ]);

            } elseif ($request->filled('from_date')) {

                $query->where('vouchers.date_bs', '>=', $request->from_date);

            } elseif ($request->filled('to_date')) {

                $query->where('vouchers.date_bs', '<=', $request->to_date);
            }


            $data = $query
                ->orderBy('vouchers.date_bs', 'asc')
                ->where('vouchers.branch_id', $request->branch_id)
                ->select(
                    'voucher_details.id',
                    'voucher_details.voucher_id',
                    'voucher_details.account_head_id',
                    'voucher_details.narration',
                    'vouchers.voucher_number',
                    'vouchers.voucher_type',
                    'vouchers.date_bs',
                    'vouchers.date',
                    'account_heads.name as account_head_name',
                    DB::raw('FORMAT(voucher_details.debit, 2) as debit'),
                    DB::raw('FORMAT(voucher_details.credit, 2) as credit')
                )
                ->get();

            return response()->json([
                "message" => "success",
                "data" => $data
            ], 200);

        } catch (ModelNotFoundException $e) {

            return response()->json([
                "message" => "Item not Found !!",
                "data" => []
            ], 200);

        } catch (QueryException $e) {

            return response()->json([
                "message" => "Database error occurred !!",
                "error" => $e->getMessage()
            ]);

        } catch (Exception $e) {

            return response()->json([
                "message" => "An unexpected error occurred !!",
                "error" => $e->getMessage()
            ]);
        }
    }


}
