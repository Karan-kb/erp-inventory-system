<?php

namespace App\Repositories;

use App\Models\PurchaseAdditionalBill;

use App\Interfaces\PurchaseAdditionalBillRepositoryInterface;
use App\Models\PurchaseAdditionalbillDetail;
use App\Http\Resources\PurchaseAdditionalBillResource;
use App\Traits\Paginator;


class PurchaseAdditionalBillRepository implements PurchaseAdditionalBillRepositoryInterface
{

    use Paginator;

    public function list(array $filters)
    {
        $query = PurchaseAdditionalBill::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $additionalBills = $query->paginate(50);
        return $this->paginated($additionalBills, PurchaseAdditionalBillResource::collection($additionalBills->items()));

    }



    public function productTypeDetails(array $filters)
    {

        $name = $filters['type_name'] ?? null;

        $productTypeDetail = PurchaseAdditionalBill::where('name', $name)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new ProductTypeResource($productTypeDetail);

    }



    public function create(array $data): PurchaseAdditionalBill
    {


        $additionalBill = PurchaseAdditionalBill::create($data);

        foreach ($data['additionals'] as $additionals) {
            PurchaseAdditionalbillDetail::create([
                "purchase_additionalbill_id" => $additionalBill->id,
                "account_head_id" => $additionals['account_head_id'],
                "amount" => $additionals['amount']
            ]);
        }
        return $additionalBill->load('additionalDetails');


    }



    public function update($id, array $data)
    {

        $addtionalBill = PurchaseAdditionalBill::findOrFail($id);
        $addtionalBill->update($data);

        $incomingIds = collect($data['additionals'])
            ->pluck('id')
            ->filter()
            ->toArray();


        PurchaseAdditionalbillDetail::where('purchase_additionalbill_id', $addtionalBill->id)
            ->whereNotIn('id', $incomingIds)
            ->delete();

        foreach ($data['additionals'] as $additionals) {

            PurchaseAdditionalbillDetail::updateOrCreate(
                [
                    "id" => $additionals['id'] ?? null,

                ],
                [
                    "purchase_additionalbill_id" => $addtionalBill->id,
                    "account_head_id" => $additionals['account_head_id'],
                    "amount" => $additionals['amount']
                ]
            );

        }

        return $addtionalBill->load('additionalDetails');


    }

    public function delete($id)
    {
        $additionalBill = PurchaseAdditionalBill::findOrFail($id);

        $usedIn = [];
     

        $additionalBill->additionalDetails()->delete();
        $additionalBill->delete();
        return true;
    }

    public function show($id)
    {

        $PurchaseAdditionalBill = PurchaseAdditionalBill::findOrFail($id);

        return new PurchaseAdditionalBillResource($PurchaseAdditionalBill);
    }


    public function activeProductTypeList()
    {
        $productTypes = ProductType::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'is_primary']);


        $response = ($productTypes->count() > 0) ? ProductTypeResource::collection($productTypes)->map(function ($productType) {
            return collect($productType)->only(['id', 'name', 'is_primary']);
        }) : [];

        return $response;

    }

}
?>