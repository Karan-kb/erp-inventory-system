<?php

namespace App\Http\Controllers;

use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\Voucher;
use App\Models\MainGroup;
use App\Models\SubGroup;
use App\Models\JournalVoucherTransaction;
use App\Models\VoucherDetails;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Log;
use Validator;

class JournalVoucherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Step 1: Eager load relationships
        $query = Voucher::where('voucher_type', 'journal_voucher')->whereNull('deleted_at');

        // Step 2: Apply keyword filter if needed
        if ($request->has('keywords')) {
            $query->where('voucher_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhere('reference_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        // Step 3: Paginate
        $data = $query->paginate(50);

        // Step 4: Transform each item to include names instead of nested objects
        $data->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'company' => $item->company_id,
                'voucher_number' => $item->voucher_number,
                'reference_number' => $item->reference_number,
                'date' => $item->date,
                'salesman_id' => $item->salesman_id,
                'salesman_name' => optional($item->salesman)->name,
                'project_id' => $item->project_id,
                'project_name' => optional($item->project)->name,
                'transactions' => $item->transactions, // already loaded
            ];
        });
        // Step 5: Return transformed paginated response
        return response()->json($data);
    }







    public function print(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_date' => 'required',
            'to_date' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $query = JournalVoucherTransaction::select('journal_voucher_transactions.id', 'particulars', 'debit', 'credit', DB::raw('SUM((debit) - COALESCE(credit, 0)) OVER (ORDER BY id) as balance'), 'projects.name', 'reference_number', 'journal_vouchers.date')->leftJoin("journal_vouchers", 'journal_vouchers.id', '=', 'journal_voucher_transactions.journal_voucher_id')->leftJoin("projects", 'projects.id', '=', 'journal_vouchers.project_id')->when(isset($request->from_date) && isset($request->to_date), function ($query1) use ($request) {
            $query1->where('date', '>=', $request->from_date)->where('date', '<=', $request->to_date);
        })->get();


        return response()->json($query);
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'voucher_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('vouchers')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->company_id)
                                ->whereNull('deleted_at');
                        }),
                ],
                // 'reference_number' => [
                //     'required',
                //     'string',
                //     'max:255',
                //     Rule::unique('vouchers')->where(function ($query) use ($request) {
                //         return $query->where('company_id', $request->company_id)
                //             ->whereNull('deleted_at');
                //     }),
                // ],

                'project_id' => 'nullable',
                'salesman_id' => 'nullable',

                'date' => 'nullable|string',
                'date_bs' => 'nullable|string',

                'description' => 'nullable|string|max:255',
                'total_amount' => 'nullable|numeric',
                'transactions' => 'nullable|array',
                'transactions.*.main_group_id' => 'nullable|integer|exists:main_groups,id',
                'transactions.*.account_group_id' => 'nullable|integer|exists:account_groups,id',
                'transactions.*.account_head_id' => 'nullable|integer|exists:account_heads,id',
                'transactions.*.sub_group_id' => 'nullable|integer|exists:sub_groups,id',
                'transactions.*.account_code' => 'nullable|string',
                'transactions.*.doc_no' => 'nullable|string',
                'transactions.*.narration' => 'nullable|string|max:255',
                'transactions.*.payment_mode' => 'nullable|string|max:255',
                'transactions.*.debit' => 'nullable|numeric',
                'transactions.*.credit' => 'nullable|numeric',
                'company_id' => 'integer',
                'branch_id' => 'integer|exists:branches,id'
            ]);
            $debit = collect($validated['transactions'] ?? [])->sum('debit');
            $credit = collect($validated['transactions'] ?? [])->sum('credit');



            if (round($debit, 2) !== round($credit, 2)) {
                return response()->json([
                    'error' => 'Validation failed !!',
                    'messages' => ["Debit ({$debit}) must equal Credit ({$credit})"]
                ], 422);
            }
            $validated['voucher_type'] = 'journal_voucher';

            $journal = DB::transaction(function () use ($validated, $id, &$product) {
                $item = Voucher::findOrFail($id);
                $item->update($validated);
                $item->voucherDetails()->delete();
                if (isset($validated['transactions'])) {
                    foreach ($validated['transactions'] as $transactionData) {
                        VoucherDetails::create([
                            'voucher_id' => $item->id,
                            'company_id' => $validated['company_id'],
                            'branch_id' => $validated['branch_id'],
                            'main_group_id' => $transactionData['main_group_id'] ?? null,
                            'sub_group_id' => $transactionData['sub_group_id'] ?? null,
                            'account_head_id' => $transactionData['account_head_id'] ?? null,
                            'account_group_id' => $transactionData['account_group_id'] ?? null,
                            'voucher_number' => $validated['voucher_number'],
                            'narration' => $transactionData['narration'] ?? null,
                            'doc_no' => $transactionData['doc_no'] ?? null,
                            'reference_id' => $item->id,
                            'reference_type' => 'journal_voucher',
                            'date' => $validated['date'],
                            'debit' => $transactionData['debit'] ?? 0,
                            'credit' => $transactionData['credit'] ?? 0,
                            'date_bs' => $validated['date_bs'],
                            'description' => $transactionData['narration'] ?? null,
                            'total_amount' => $transactionData['debit'] ?? 0,
                        ]);
                    }

                }


                $journal = $item->load('voucherDetails');

                return $journal;





            });

            return response()->json([
                'message' => 'Journal Voucher Updated',
                'item' => $journal
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Item not found'], 404);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }

    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'voucher_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('vouchers')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at');
                }),
            ],
            // 'reference_number' => [
            //     'required',
            //     'string',
            //     'max:255',
            //     Rule::unique('vouchers')->where(function ($query) use ($request) {
            //         return $query->where('company_id', $request->company_id)
            //             ->whereNull('deleted_at');
            //     }),
            // ],

            'project_id' => 'nullable',
            'salesman_id' => 'nullable',

            'date' => 'nullable|string',
            'date_bs' => 'nullable|string',
            'description' => 'nullable|string|max:255',
            'total_amount' => 'nullable|numeric',
            'transactions' => 'nullable|array',
            'transactions.*.main_group_id' => 'nullable|integer|exists:main_groups,id',
            'transactions.*.account_group_id' => 'nullable|integer|exists:account_groups,id',
            'transactions.*.account_head_id' => 'nullable|integer|exists:account_heads,id',
            'transactions.*.sub_group_id' => 'nullable|integer|exists:sub_groups,id',
            'transactions.*.account_code' => 'nullable|string',
            'transactions.*.doc_no' => 'nullable|string',
            'transactions.*.narration' => 'nullable|string|max:255',
            'transactions.*.payment_mode' => 'nullable|string|max:255',
            'transactions.*.debit' => 'nullable|numeric',
            'transactions.*.credit' => 'nullable|numeric',
            'company_id' => 'integer',
            'branch_id' => 'integer|exists:branches,id'
        ]);
        $debit = collect($validated['transactions'] ?? [])->sum('debit');
        $credit = collect($validated['transactions'] ?? [])->sum('credit');

        if (round($debit, 2) !== round($credit, 2)) {
            return response()->json([
                'error' => 'Validation failed !!',
                'messages' => ["Debit ({$debit}) must equal Credit ({$credit})"]
            ], 422);
        }

        $validated['voucher_type'] = 'journal_voucher';
        $item = Voucher::create($validated);

        DB::transaction(function () use ($validated, $item) {
            if (isset($validated['transactions'])) {
                foreach ($validated['transactions'] as $transactionData) {
                    VoucherDetails::create([
                        'voucher_id' => $item->id,
                        'company_id' => $validated['company_id'],
                        'branch_id' => $validated['branch_id'],
                        'main_group_id' => $transactionData['main_group_id'] ?? null,
                        'sub_group_id' => $transactionData['sub_group_id'] ?? null,
                        'account_head_id' => $transactionData['account_head_id'] ?? null,
                        'account_group_id' => $transactionData['account_group_id'] ?? null,
                        'voucher_number' => $validated['voucher_number'],
                        'narration' => $transactionData['narration'] ?? null,
                        'doc_no' => $transactionData['doc_no'] ?? null,
                        'reference_id' => $item->id,
                        'reference_type' => 'journal_voucher',
                        'date' => $validated['date'],
                        'debit' => $transactionData['debit'] ?? 0,
                        'credit' => $transactionData['credit'] ?? 0,
                        'date_bs' => $validated['date_bs'],
                        'description' => $transactionData['narration'] ?? null,
                        'total_amount' => $transactionData['debit'] ?? 0,
                    ]);
                }

            }
        });

        return response()->json([
            'item' => $item,
            'action' => 'created',
        ], 201);
    }
    public function mainGroupList(): JsonResponse
    {
        $companyId = request('company_id');

        $query = MainGroup::query()
            ->select('id', 'name')
            ->whereNull('deleted_at');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $groups = $query->orderBy('name')->get();

        return response()->json($groups);
    }

    public function subGroupList(): JsonResponse
    {
        $companyId = request('company_id');
        $mainGroupId = request('main_group_id');

        $query = SubGroup::query()
            ->select('id', 'name', 'main_group_id')
            ->whereNull('deleted_at');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($mainGroupId) {
            $query->where('main_group_id', $mainGroupId);
        }

        $subGroups = $query->orderBy('name')->get();

        return response()->json($subGroups);   // 200 OK
    }


    public function accountGroupList(): JsonResponse
    {
        $companyId = request('company_id');
        $mainGroupId = request('main_group_id');
        $subGroupId = request('sub_group_id');

        $query = AccountGroup::query()
            ->select('id', 'name', 'sub_group_id', 'main_group_id')
            ->whereNull('deleted_at');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($mainGroupId) {
            $query->where('main_group_id', $mainGroupId);
        }

        if ($subGroupId) {
            $query->where('sub_group_id', $subGroupId);
        }

        $accountGroups = $query->orderBy('name')->get();


        return response()->json($accountGroups);
    }

    public function accountHeadList(): JsonResponse
    {

        $companyId = request('company_id');
        $mainGroupId = request('main_group_id');
        $subGroupId = request('sub_group_id');
        $accountGroupId = request('account_group_id');

        $query = AccountHead::query()
            ->select(
                'account_heads.id',
                'account_heads.name',
                'account_heads.account_group_id',
                'account_groups.sub_group_id',
                'account_groups.main_group_id'
            )
            ->join('account_groups', 'account_groups.id', '=', 'account_heads.account_group_id')
            ->whereNull('account_heads.deleted_at');

        if ($companyId) {
            $query->where('account_heads.company_id', $companyId);
        }

        if ($mainGroupId) {
            $query->where('account_groups.main_group_id', $mainGroupId);
        }

        if ($subGroupId) {
            $query->where('account_groups.sub_group_id', $subGroupId);
        }

        if ($accountGroupId) {
            $query->where('account_heads.account_group_id', $accountGroupId);
        }

        $accountHeads = $query->orderBy('account_heads.name')->get();

        return response()->json($accountHeads);
    }
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $voucher = Voucher::where('company_id', $request->company_id)->whereNULL('deleted_at')
                ->with([
                    'voucherDetails.mainGroup:id,name',
                    'voucherDetails.accountGroup:id,name',
                    'voucherDetails.accountHead:id,name',
                    'voucherDetails.subGroup:id,name',
                    // 'project:id,name',
                    // 'salesman:id,name',

                ])
                ->findOrFail($id);

            $voucherArray = $voucher->toArray();

            $voucherArray['transactions'] = collect($voucherArray['voucher_details'])->map(function ($item) {

                $item['debit'] = number_format((float) $item['debit'], 2, '.', '');
                $item['credit'] = number_format((float) $item['credit'], 2, '.', '');

                return $item;
            })->toArray();
            unset($voucherArray['voucher_details']);
            return response()->json([
                'item' => $voucherArray
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Journal Voucher not found !'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database query error occurred!'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Voucher::findOrFail($id);
            $item->voucherDetails()->delete();
            $item->delete();
            return response()->json(['message' => 'Journal Voucher deleted!!']);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}
