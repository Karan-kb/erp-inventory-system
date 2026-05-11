<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Voucher extends BaseTenantModel
{
    use SoftDeletes;
    protected $fillable = [
        'company_id',
        'branch_id',
        'voucher_number',
        'voucher_type',
        'reference_id',
        'reference_type',
        'date',
        'date_bs',
        'description',
        'total_amount',
        'is_cancel',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $dates = ['deleted_at'];

   
    public function accountHead()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');

    }

    public function accountGroup()
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');

    }

    public function voucherDetails()
    {
        return $this->hasMany(VoucherDetails::class, 'voucher_id');
    }

    public function voucherSummaryInnerDetail()
    {
        return $this->hasMany(VoucherInnerDetail::class, 'voucher_summary_id');

    }
}
