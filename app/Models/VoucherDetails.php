<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VoucherDetails extends BaseTenantModel
{
    use SoftDeletes;
    protected $fillable = [
        'voucher_id',
        'company_id',
        'branch_id',
        'account_head_id',
        'narration',
        'debit',
        'credit',
        'tax_amount',
        'taxable_amount',
        'doc_no',
        'payment_mode',
        'payment_account_id',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];



    public function accountHead()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');

    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');

    }

    public function accountGroup()
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');

    }

    public function mainGroup()
    {
        return $this->belongsTo(MainGroup::class, 'main_group_id');

    }

    public function subGroup()
    {
        return $this->belongsTo(SubGroup::class, 'sub_group_id');

    }
}
