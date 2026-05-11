<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class PurchaseAdditionalbill extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'stock_id',
        'is_active'
    ];

    public function stock()
    {
        return $this->belongsTo(stock::class, 'stock_id');
    }

    public function additionalDetails()
    {
        return $this->hasMany(PurchaseAdditionalbillDetail::class, 'purchase_additionalbill_id');
    }

}
