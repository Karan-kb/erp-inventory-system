<?php

namespace App\Http\Controllers;


use App\Models\Voucher;
use App\Models\Party;
use App\Models\AccountHead;
use App\Models\VoucherDetails;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PaymentVoucherController extends Controller
{
    public function index(Request $request)
    {

        $query = Voucher::whereNull('deleted_at')
            ->where('voucher_type', 'payment_voucher');

        return response()->json($query->paginate(50));

    }
    public function update(Request $request, $id): JsonResponse
    {

        try {
            $validator = Validator::make($request->all(), [
                'date' => 'nullable|date',
                'date_bs' => 'nullable|string',
                'voucher_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('vouchers')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],


                'payment_voucher_list' => 'nullable|array',

                'payment_voucher_list.*.party_id' => 'nullable|integer|exists:parties,id',
                'payment_voucher_list.*.account_head_id' => 'required|integer',
                'payment_voucher_list.*.debit' => 'nullable|numeric',
                'payment_voucher_list.*.credit' => 'nullable|numeric',
                'payment_voucher_list.*.payment_mode' => 'nullable|string',
                'payment_voucher_list.*.remarks' => 'nullable|string',
                'payment_voucher_list.*.doc_no' => 'nullable|string',
                'payment_voucher_list.*.remaining_balance' => 'nullable|numeric',
                'company_id' => 'integer|required',
                'branch_id' => 'integer|exists:branches,id'
            ]);



            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }
            $validated = $validator->validated();


            $validated['voucher_type'] = 'payment_voucher';

            $debit = collect($validated['payment_voucher_list'] ?? [])->sum('credit');

            $receiptvoucher = DB::transaction(function () use ($validated, $id, $debit) {
                $item = Voucher::findOrFail($id);
                $item->update($validated);
                $item->voucherDetails()->delete();

                if (isset($validated['payment_voucher_list'])) {
                    foreach ($validated['payment_voucher_list'] as $transactionData) {
                        $partyID = $transactionData['party_id'];
                        $bankID = Party::where('id', $partyID)->whereNULL('deleted_at')->value('bank_id');

                        $accountHeadId = NULL;
                        if ($transactionData['payment_mode'] == "bank") {
                            $accountHeadId = AccountHead::where('bank_id', $bankID)->whereNULL('deleted_at')->value('id') ?? $transactionData['account_head_id'] ?? null;


                        } elseif ($transactionData['payment_mode'] == "cash") {
                            $accountHeadId = 1;
                        } elseif ($transactionData["payment_mode"] == "credit") {
                            $accountHeadId = AccountHead::where('party_id', $partyID)->whereNULL('deleted_at')->value('id') ?? $transactionData['account_head_id'] ?? null;

                        }
                        VoucherDetails::create([
                            'voucher_id' => $item->id,
                            'company_id' => $validated['company_id'],
                            'branch_id' => $validated['branch_id'],
                            'main_group_id' => $transactionData['main_group_id'] ?? null,
                            'sub_group_id' => $transactionData['sub_group_id'] ?? null,
                            'account_head_id' => $accountHeadId,
                            'account_group_id' => $transactionData['account_group_id'] ?? null,
                            'voucher_number' => $validated['voucher_number'],
                            'narration' => $transactionData['narration'] ?? null,
                            'doc_no' => $transactionData['doc_no'] ?? null,

                            'reference_type' => 'receipt_voucher',
                            'date' => $validated['date'],
                            // 'debit' => $transactionData['debit'] ?? 0,
                            'credit' => $transactionData['credit'] ?? 0,
                            'date_bs' => $validated['date_bs'],
                            'description' => $transactionData['narration'] ?? null

                        ]);


                    }

                    VoucherDetails::create([
                        'voucher_id' => $item->id,
                        'company_id' => $validated['company_id'],
                        'branch_id' => $validated['branch_id'],
                        'main_group_id' => $transactionData['main_group_id'] ?? null,
                        'sub_group_id' => $transactionData['sub_group_id'] ?? null,
                        'account_head_id' => AccountHead::where('party_id', $partyID)->whereNULL('deleted_at')->value('id') ?? $transactionData['account_head_id'] ?? null,
                        'account_group_id' => $transactionData['account_group_id'] ?? null,
                        'voucher_number' => $validated['voucher_number'],
                        'narration' => $transactionData['narration'] ?? null,
                        'doc_no' => $transactionData['doc_no'] ?? null,
                        'reference_id' => $item->id,
                        'reference_type' => 'payment_voucher',
                        'date' => $validated['date'],
                        // 'credit' => $transactionData['debit'] ?? 0,
                        'debit' => $debit ?? 0,
                        'date_bs' => $validated['date_bs'],
                        'description' => $transactionData['narration'] ?? null

                    ]);

                }

                return $item;


            });

            return response()->json([
                'message' => 'Payment Voucher Created',
                'item' => $receiptvoucher->load('voucherDetails'),
            ], 201);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Creation failed: ' . $e->getMessage()], 500);
        }
    }


    public function store(Request $request): JsonResponse
    {

        try {
            $validator = Validator::make($request->all(), [
                'date' => 'nullable|date',
                'date_bs' => 'nullable|string',
                'voucher_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('vouchers')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],


                'payment_voucher_list' => 'nullable|array',

                'payment_voucher_list.*.party_id' => 'nullable|integer|exists:parties,id',
                'payment_voucher_list.*.account_head_id' => 'required|integer',
                'payment_voucher_list.*.debit' => 'nullable|numeric',
                'payment_voucher_list.*.credit' => 'nullable|numeric',
                'payment_voucher_list.*.payment_mode' => 'nullable|string',
                'payment_voucher_list.*.remarks' => 'nullable|string',
                'payment_voucher_list.*.doc_no' => 'nullable|string',
                'payment_voucher_list.*.remaining_balance' => 'nullable|numeric',
                'company_id' => 'integer|required',
                'branch_id' => 'integer|exists:branches,id'
            ]);



            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }
            $validated = $validator->validated();


            $validated['voucher_type'] = 'payment_voucher';
            $item = Voucher::create($validated);
            $debit = collect($validated['payment_voucher_list'] ?? [])->sum('credit');

            $receiptvoucher = DB::transaction(function () use ($validated, $item, $debit) {

                if (isset($validated['payment_voucher_list'])) {
                    foreach ($validated['payment_voucher_list'] as $transactionData) {
                        $partyID = $transactionData['party_id'];
                        $bankID = Party::where('id', $partyID)->whereNULL('deleted_at')->value('bank_id');
                        $debitAccoountHeadId = AccountHead::where('party_id', $partyID)->whereNULL('deleted_at')->value('id') ?? $transactionData['account_head_id'] ?? null;

                        VoucherDetails::create([
                            'voucher_id' => $item->id,
                            'company_id' => $validated['company_id'],
                            'branch_id' => $validated['branch_id'],
                            'main_group_id' => $transactionData['main_group_id'] ?? null,
                            'sub_group_id' => $transactionData['sub_group_id'] ?? null,
                            'account_head_id' => $transactionData['account_head_id'],
                            'account_group_id' => $transactionData['account_group_id'] ?? null,
                            'voucher_number' => $validated['voucher_number'],
                            'narration' => $transactionData['narration'] ?? null,
                            'doc_no' => $transactionData['doc_no'] ?? null,

                            'reference_type' => 'receipt_voucher',
                            'date' => $validated['date'],
                            // 'debit' => $transactionData['debit'] ?? 0,
                            'credit' => $transactionData['credit'] ?? 0,
                            'date_bs' => $validated['date_bs'],
                            'description' => $transactionData['narration'] ?? null

                        ]);


                    }

                    VoucherDetails::create([
                        'voucher_id' => $item->id,
                        'company_id' => $validated['company_id'],
                        'branch_id' => $validated['branch_id'],
                        'main_group_id' => $transactionData['main_group_id'] ?? null,
                        'sub_group_id' => $transactionData['sub_group_id'] ?? null,
                        'account_head_id' => $debitAccoountHeadId,
                        'account_group_id' => $transactionData['account_group_id'] ?? null,
                        'voucher_number' => $validated['voucher_number'],
                        'narration' => $transactionData['narration'] ?? null,
                        'doc_no' => $transactionData['doc_no'] ?? null,
                        'reference_id' => $item->id,
                        'reference_type' => 'payment_voucher',
                        'date' => $validated['date'],
                        // 'credit' => $transactionData['debit'] ?? 0,
                        'debit' => $debit ?? 0,
                        'date_bs' => $validated['date_bs'],
                        'description' => $transactionData['narration'] ?? null

                    ]);

                }

                return $item;


            });

            return response()->json([
                'message' => 'Payment Voucher Created',
                'item' => $receiptvoucher->load('voucherDetails'),
            ], 201);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Creation failed: ' . $e->getMessage()], 500);
        }
    }



    public function show(Request $request, $id): JsonResponse
    {
        try {
            $voucher = Voucher::where('company_id', $request->company_id)->whereNull('deleted_at')
                ->with([
                    'voucherDetails'

                ])
                ->findOrFail($id);


            return response()->json([
                'voucher' => $voucher
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Payment Voucher not found!'], 404);

        } catch (QueryException $e) {

            return response()->json(['error' => 'Database query error occurred!'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Unexpected error occurred!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Voucher::findOrFail($id);
            $item->VoucherDetails()->delete();
            $item->delete();

            return response()->json(['message' => 'Payment Voucher deleted!!']);


        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Item not found !!'], 404);
        } catch (QueryException $e) {


            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {


            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }


}
