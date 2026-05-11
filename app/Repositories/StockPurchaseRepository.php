<?php

namespace App\Repositories;
use Exception;
use Illuminate\Support\Facades\DB;
use App\Services\TransactionImplementService;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\Stock;
use App\Models\StockProduct;
use App\Models\Voucher;
use App\Models\StockMovement;
use App\Models\FiscalYear;
use App\Services\VoucherReportService;

use App\Models\Vat;
use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\Party;
use App\Models\ProductList;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\UnitConversionService;
use App\Services\QuantityIndexService;
use App\Services\CurrencyFormatService;
use App\Services\DateFormatService;
use App\Models\StockProductFieldValue;

use App\Interfaces\StockPurchaseRepositoryInterface;
use Psy\Exception\ThrowUpException;

class StockPurchaseRepository implements StockPurchaseRepositoryInterface
{

    protected $unitConversionService;
    protected $quantityIndexService;

    protected $currencyFormatService;

    protected $dateFormatService;

    protected $taxImplementService;
    protected $voucherReportService;

    public function __construct(
        UnitConversionService $unitConversionService,
        QuantityIndexService $quantityIndexService,
        CurrencyFormatService $currencyFormatService,
        TransactionImplementService $taxImplementService,
        VoucherReportService $voucherReportService

    ) {
        $this->unitConversionService = $unitConversionService;
        $this->quantityIndexService = $quantityIndexService;
        $this->currencyFormatService = $currencyFormatService;

        $this->taxImplementService = $taxImplementService;
        $this->voucherReportService = $voucherReportService;
    }
    public function create(array $data)
    {

        DB::beginTransaction();

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $appliedVat = Vat::where('is_active', 1)->pluck('vat_percent')->first() ?? 0;



        $stockData = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'purchase',
            'bill_number' => $data['bill_number'] ?? null,
            'pan_number' => $data['pan_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? 0,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? 0,
            'party_id' => $data['party_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
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




        foreach ($data['stock_products'] as $product) {
            $trackerId = (string) Str::uuid();

            //@todo: move this logic to a service class and inject here for better separation of concerns

            //check if there is quantity and measure unit, if not skip the product


            $quantity = $this->unitConversionService->convertToBaseUnit(
                $product['measure_unit_id'],
                $product['quantity']
            );

            $stockValidated = [

                'stock_id' => $stock->id,
                'fiscal_year_id' => $fiscalYearId,
                'product_id' => $product['product_id'],
                'measure_unit_id' => $product['measure_unit_id'],
                'type' => 'purchase',
                'quantity' => $quantity,
                'is_vatable' => $product['is_vatable'],
                'stock_type' => $product['stock_type'] ?? null,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'],
                'direction' => $product['direction'] ?? 'in',
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


            $stockProduct = StockProduct::create($stockValidated);


            $stockMovementProduct = null;
            if (!empty($product['free_quantity']) && $product['free_quantity'] > 0) {
                $freeQuantity = $this->unitConversionService->convertToBaseUnit(
                    $product['measure_unit_id'],
                    $product['free_quantity']
                );

                $movementValidated = [
                    'stock_id' => $stock->id,
                    'stock_product_id' => $stockProduct->id,
                    'fiscal_year_id' => $fiscalYearId,
                    'company_id' => $data['company_id'],
                    'branch_id' => $data['branch_id'],
                    'product_id' => $product['product_id'],
                    'measure_unit_id' => $product['measure_unit_id'],
                    'type' => 'purchase',
                    'quantity' => $freeQuantity,
                    'stock_type' => 'free',
                    'is_vatable' => $product['is_vatable'],
                    'direction' => $product['direction'] ?? 'in',
                    'party_id' => $data['party_id'] ?? null,
                    'expiry_date' => $product['expiry_date'] ?? null,
                    'mfd' => $product['mfd'] ?? null,
                    'price' => null,
                    'discount_percent' => null,
                    'discount_amount' => null,
                    'amount' => null,
                    'batch_no' => $product['batch_no'] ?? null,
                    'tracker_id' => $trackerId,
                ];

                $stockMovementProduct = StockMovement::create($movementValidated);
            }


            if (!empty($product['field_values'])) {



                foreach ($product['field_values'] as $quantityIndex => $group) {

                    foreach ($group as $field) {

                        $isFree = ($field['quantity_type'] ?? 'regular') === 'free';
                        $fieldValueData = [

                            'stock_id' => $stock->id,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProduct->id,
                            'stock_movement_id' => $isFree ? $stockMovementProduct->id ?? null : null,
                            'product_id' => $stockProduct->product_id,
                            'quantity_index' => $quantityIndex,
                            'quantity_type' => $field['quantity_type'] ?? 'regular',
                            'key' => $field['key'],
                            'value' => $field['value'],

                        ];

                        StockProductFieldValue::create($fieldValueData);


                    }



                }
            }

            $voucherReport = $this->voucherReportService->generateVoucherReport($stock->id, 'purchase', $stockData);

        }

        DB::commit();

        return $stock->load('stockProducts.stockProductFieldValues');



    }

    public function update($id, array $data)
    {
        DB::beginTransaction();

        $stock = Stock::with('stockProducts.stockProductFieldValues')
            ->findOrFail($id);

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $appliedVat = Vat::where('is_active', 1)->pluck('vat_percent')->first() ?? 0;


        $stockValidated = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'purchase',
            'bill_number' => $data['bill_number'] ?? null,
            'pan_number' => $data['pan_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'balance' => $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0,
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
            'health_insurance' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
            'freight_amount' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $this->currencyFormatService->cleanCurrency($data['roundoff_amount'] ?? 0) ?? 0,

            'total_amount' => $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0,
            'payment' => isset($data['payment']) ? json_encode($data['payment']) : null,
            'remarks' => $data['remarks'] ?? null,
        ];

        $stock->update($stockValidated);

        $panNumber = $data['pan_number'] ?? null;

        $panExists = Party::where('pan_number', $panNumber)
            ->where('id', '!=', $data['party_id'])
            ->exists();

        if ($panExists) {

            throw new \Exception('Pan Number already exists.');

        }

        Party::where('id', $panNumber)->update([
            'pan_number' => $panNumber
        ]);

        $incomingProductIds = [];

        foreach ($data['stock_products'] ?? [] as $product) {
            $trackerId = (string) Str::uuid();


            $baseQty = $this->unitConversionService->convertToBaseUnit(
                $product['measure_unit_id'] ?? null,
                $product['quantity'] ?? 0
            );

            $stockProductData = [

                'stock_id' => $stock->id,
                'fiscal_year_id' => $fiscalYearId,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'],
                'product_id' => $product['product_id'],
                'measure_unit_id' => $product['measure_unit_id'],
                'type' => 'purchase',
                'quantity' => $baseQty,
                'is_vatable' => $product['is_vatable'] ?? 0,
                'stock_type' => $product['stock_type'] ?? null,
                'direction' => 'in',
                'party_id' => $product['party_id'] ?? $data['party_id'] ?? null,
                'expiry_date' => $product['expiry_date'] ?? null,
                'mfd' => $product['mfd'] ?? null,
                'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                'batch_no' => $product['batch_no'] ?? null,
                'tracker_id' => $trackerId,
            ];

            $stockProduct = StockProduct::updateOrCreate(
                [
                    'id' => $product['id'] ?? null,
                ],
                $stockProductData
            );

            $incomingProductIds[] = $stockProduct->id;

            \Log::info('StockProduct upsert', [
                'sent_id' => $product['id'] ?? 'NEW',
                'result_id' => $stockProduct->id,
                'was_created' => $stockProduct->wasRecentlyCreated ? 'YES' : 'NO',
                'quantity_sent' => $product['quantity'] ?? null,
                'quantity_used' => $baseQty,
            ]);


            $movement = null;
            $freeQtyInput = $product['free_quantity'] ?? 0;

            if ($freeQtyInput > 0) {

                $freeQty = $this->unitConversionService->convertToBaseUnit(
                    $product['measure_unit_id'] ?? null,
                    $freeQtyInput
                );

                $movementValidatedData = [

                    'stock_id' => $stock->id,
                    'fiscal_year_id' => $fiscalYearId,
                    'company_id' => $data['company_id'],
                    'branch_id' => $data['branch_id'],
                    'product_id' => $product['product_id'],
                    'measure_unit_id' => $product['measure_unit_id'],
                    'type' => 'purchase',
                    'stock_type' => 'free',
                    'quantity' => $freeQty,
                    'direction' => 'in',
                    'party_id' => $product['party_id'] ?? $data['party_id'] ?? null,
                    'expiry_date' => $product['expiry_date'] ?? null,
                    'mfd' => $product['mfd'] ?? null,
                    'price' => null,
                    'discount_percent' => null,
                    'discount_amount' => null,
                    'amount' => null,
                    'batch_no' => $product['batch_no'] ?? null,
                    'is_vatable' => $product['is_vatable'] ?? 0,
                    'tracker_id' => $trackerId,


                ];

                $movement = StockMovement::updateOrCreate(
                    [
                        'stock_product_id' => $stockProduct->id,
                        'stock_type' => 'free',
                    ],
                    $movementValidatedData
                );
            } else {

                StockMovement::where('stock_product_id', $stockProduct->id)
                    ->where('stock_type', 'free')
                    ->delete();
            }


            $incomingFieldIds = [];

            if (!empty($product['field_values']) || !empty($product['stock_product_field_values'])) {
                $fieldValues = $product['field_values'] ?? $product['stock_product_field_values'] ?? [];

                foreach ($fieldValues as $group) {


                    $groupIndex = null;


                    foreach ($group as $field) {
                        if (isset($field['quantity_index'])) {
                            $groupIndex = (int) $field['quantity_index'];
                            break;
                        }
                    }


                    if ($groupIndex === null) {
                        $groupIndex = $this->quantityIndexService->getNextQuantityIndex($stockProduct->id);
                    }


                    foreach ($group as $field) {
                        $isFree = ($field['quantity_type'] ?? 'regular') === 'free';

                        $fieldValueData = [

                            'stock_id' => $stock->id,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProduct->id,
                            'stock_movement_id' => $isFree ? ($movement?->id ?? null) : null,
                            'product_id' => $stockProduct->product_id,
                            'quantity_index' => $groupIndex,
                            'quantity_type' => $field['quantity_type'] ?? 'regular',
                            'key' => $field['key'],
                            'value' => $field['value'],

                        ];

                        $fieldValue = StockProductFieldValue::updateOrCreate(
                            [
                                'id' => $field['id'] ?? null,
                            ],
                            $fieldValueData
                        );

                        $incomingFieldIds[] = $fieldValue->id;
                    }
                }

            }


            StockProductFieldValue::where('stock_product_id', $stockProduct->id)
                ->when($incomingFieldIds, fn($q) => $q->whereNotIn('id', $incomingFieldIds))
                ->delete();
        }


        $stock->stockProducts()
            ->whereNotIn('id', $incomingProductIds)
            ->delete();


        $stock->vouchers()->with('voucherDetails')->get()->each(function (Voucher $voucher) {
            $voucher->voucherDetails()->delete();
            $voucher->delete();
        });

        $this->voucherReportService->generateVoucherReport(
            $stock->id,
            'purchase',

            $stockValidated
        );


        DB::commit();

        return $stock->load('stockProducts.stockProductFieldValues');
    }




    public function list(array $filters)
    {
        $stocks = Stock::where('type', 'purchase')
            ->whereNull('deleted_at')
            ->where('branch_id', $filters['branch_id'])
            ->with('party')
            ->paginate(50);

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
            'party',
            'stockProducts' => function ($query) {
                $query->whereNull('deleted_at')
                    ->with([
                        'stockProductFieldValues' => function ($q) {
                            $q->whereNull('deleted_at');
                        },
                        'stockMovements' => function ($q) {
                            $q->where('type', 'purchase')
                                ->where('stock_type', 'free')
                                ->whereNull('deleted_at');
                        },

                    ]);
            }
        ])
            ->whereNull('deleted_at')
            ->where('type', 'purchase')
            ->findOrFail($id);

        $stock->party_pan_number = $stock->party?->pan_number;
        $stock->party_phone = $stock->party?->phone;
        $stock->party_address = $stock->party?->address;

        $stock->stockProducts->transform(function ($product) {


            $freeQty = $product->stockMovements->sum('quantity') ?? 0;

            $attributes = $product->toArray();

            $fieldValues = $attributes['stock_product_field_values'] ?? [];

            $unitId = $product->measure_unit_id;

            $unitQuantity = $product->measureUnit?->quantity ?? 1;

            $convertedQty = $unitQuantity > 0
                ? ($product->quantity / $unitQuantity)
                : $product->quantity;

            unset($attributes['stock_product_field_values']);
            unset($attributes['stock_movements']);

            $newProduct = [];

            foreach ($attributes as $key => $value) {
                $newProduct[$key] = $value;

                if ($key === 'quantity') {
                    $newProduct['free_quantity'] = $freeQty;
                }
            }


            $newProduct['product_name'] = $product->product->name ?? null;

            $newProduct['quantity'] = number_format($convertedQty, 2, '.', '');
            $newProduct['display_quantity'] = $product->quantity;


            $productFieldNumber = $product->product->product_field_number ?? null;

            $config = $productFieldNumber
                ? config("product_fields.{$productFieldNumber}")
                : null;

            $configFields = collect($config['fields'] ?? [])
                ->keyBy('key');

            // $configFields = collect($config['fields'] ?? [])
            //     ->mapWithKeys(function ($field) {

            //         
            //         $key = strtolower($field['name'] ?? $field['label'] ?? '');

            //         return [$key => $field];
            //     });


            $newProduct['field_values'] = collect($fieldValues)
                ->map(function ($item) use ($configFields) {

                    $fieldConfig = $configFields[$item['key']] ?? null;

                    $isDropdown = ($fieldConfig['type'] ?? null) == 'dropdown';

                    return [

                        'id' => $item['id'] ?? null,
                        'stock_id' => $item['stock_id'] ?? null,
                        'company_id' => $item['company_id'] ?? null,
                        'branch_id' => $item['branch_id'] ?? null,
                        'stock_product_id' => $item['stock_product_id'] ?? null,
                        'stock_movement_id' => $item['stock_movement_id'] ?? null,
                        'product_id' => $item['product_id'] ?? null,
                        'quantity_index' => $item['quantity_index'] ?? null,
                        'quantity_type' => $item['quantity_type'] ?? null,
                        'key' => $item['key'] ?? null,
                        'value' => $item['value'] ?? null,
                        'created_at' => $item['created_at'] ?? null,
                        'updated_at' => $item['updated_at'] ?? null,
                        'deleted_at' => $item['deleted_at'] ?? null,


                        'type' => $fieldConfig['type'] ?? 'text',
                        'options' => $isDropdown ? ($fieldConfig['options'] ?? []) : null,
                    ];
                })
                ->values()
                ->toArray();


            $productId = $product->product_id;

            $productUnitIds = Product::where('id', $productId)
                ->pluck('measure_unit_id');

            $productListUnitIds = ProductList::where('product_id', $productId)
                ->pluck('measure_unit_id');

            $unitIds = collect()
                ->merge($productUnitIds)
                ->merge($productListUnitIds)
                ->filter()
                ->unique()
                ->values();

            $measureUnits = MeasureUnit::whereIn('id', $unitIds)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'quantity'])
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'measure_unit_quantity' => $unit->quantity ?? null,
                    ];
                });

            $newProduct['measure_units'] = $measureUnits;

            return $newProduct;
        });

        return $stock;
    }
    public function delete($id)
    {

        DB::beginTransaction();

        $stock = Stock::where('type', 'purchase')
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $stockProductIds = StockProduct::where('stock_id', $stock->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        StockProductFieldValue::whereIn('stock_product_id', $stockProductIds)
            ->whereNull('deleted_at')
            ->delete();

        StockMovement::whereIn('stock_product_id', $stockProductIds)
            ->where('type', 'purchase')
            ->where('stock_type', 'free')
            ->whereNull('deleted_at')
            ->delete();

        StockProduct::whereIn('id', $stockProductIds)
            ->delete();

        $stock->delete();
        DB::commit();
        return true;





    }
}

?>