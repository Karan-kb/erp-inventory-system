<?php

namespace App\Http\Resources;

use App\Models\PurchaseAdditionalbill;
use App\Models\PurchaseAdditionalbillDetail;
use Illuminate\Http\Request;
use App\Models\MeasureUnit;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseAdditionalBillResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        $additionDetails = PurchaseAdditionalbillDetail::where('purchase_additionalbill_id', $this->id)->whereNull('deleted_at')->get();



        return [
            'id' => $this->id,
            'stock_id' => $this->stock_id,
            'bill_number' => $this->bill_number,
            'purchase_additionalbill_details' => $additionDetails




        ];
    }


}
