<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockPurchase extends Model
{
    protected $table = "stocks";


    public function stockProducts()
    {
        return $this->hasMany(StockProduct::class, 'stock_id');
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class, 'reference_id')->where('reference_type', 'stock_purchase');
    }
}
