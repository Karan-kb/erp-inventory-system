<?php

namespace App\Repositories;

use App\Models\AccountHead;
use App\Models\Bank;

use App\Traits\Paginator;
use App\Interfaces\BankRepositoryInterface;
use App\Http\Resources\BankResource;

class BankRepository implements BankRepositoryInterface
{
    use Paginator;

    public function list(array $filters): array
    {
        $query = Bank::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $banks = $query->paginate(50);

        return $this->paginated($banks, BankResource::collection($banks->items()));

    }



    public function bankDetails(array $filters)
    {
        $bankName = $filters['bank_name'] ?? null;

        $bankDetail = Bank::where('name', $bankName)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new BankResource($bankDetail);

    }



    public function create(array $data): Bank
    {
        if (!empty($data['is_primary'])) {
            Bank::where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['company_id'] = $data['company_id'] ?? null;


        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;



        $bank = Bank::create($data);

        AccountHead::create([
            'name' => $bank->name,


            'is_active' => true,
            'is_primary' => true,
            'company_id' => $data['company_id'] ?? null,
            'bank_id' => $bank->id,
            'type' => 'bank',
            'code' => 1,
            'account_group_id' => 12,

        ]);

        return $bank;

    }



    public function update($id, array $data)
    {

        $bank = Bank::findOrFail($id);
        if (!empty($data['is_primary']) && $data['is_primary'] === true) {
            Bank::where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }


        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = (bool) $data['is_primary'];
        }



        $bank->update($data);

        $accountHead = AccountHead::where('bank_id', $bank->id)->first();

        if ($accountHead) {
            $accountHead->update([
                'name' => $bank->name,
                'is_active' => $bank->is_active,
                'is_primary' => $bank->is_primary,
                'company_id' => $data['company_id'] ?? null,
                'code' => 1,
                'type' => 'bank',
                'account_group_id' => 12,
                'bank_id' => $bank->id,
            ]);
        }


        return $bank->fresh();


    }

    public function delete($id)
    {
        $Bank = Bank::findOrFail($id);



        $usedIn = [];

        // if ($Bank->products()->exists()) {
        //     $usedIn[] = 'products';
        // }


        if (!empty($usedIn)) {

            throw new \Exception('in_use:' . implode(',', $usedIn));
        }
        AccountHead::where('bank_id', $Bank->id)->delete();

        $Bank->delete();

        return true;
    }

    public function show($id)
    {

        $bank = Bank::findOrFail($id);

        return new BankResource($bank);
    }


    public function activeBankList()
    {
        $banks = Bank::whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name', 'asc')
            ->get();

        $response = ($banks->count() > 0) ? BankResource::collection($banks)->map(function ($bank) {
            return collect($bank)->only(['id', 'name']);
        }) : [];


        return $response;

    }




}
?>