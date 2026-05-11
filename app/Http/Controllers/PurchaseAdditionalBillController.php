<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

use Illuminate\Database\QueryException;


use App\Interfaces\PurchaseAdditionalBillRepositoryInterface;
use App\Http\Requests\PurchaseAdditionalBillRequest\StoreRequest;
use App\Http\Requests\PurchaseAdditionalBillRequest\UpdateRequest;

class PurchaseAdditionalBillController extends Controller
{
    protected $repository;

    public function __construct(PurchaseAdditionalBillRepositoryInterface $repository)
    {
        $this->repository = $repository;

    }

    public function index(Request $request)
    {
        try {

            $data = $this->repository->list($request->all());
            return response()->json([
                "message" => "Data retrieved successfully !!",
                "data" => $data['data'],
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(
                [
                    "error" => "Item Not Found !!"
                ],
                404
            );
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);

        }
    }

    public function store(StoreRequest $request)
    {
        try {

            $data = $this->repository->create($request->validated());
            return response()->json([
                "message" => "Data created successfully !!",
                "data" => $data
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(
                [
                    "error" => "Item Not Found !!"
                ],
                404
            );
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);

        }
    }

    public function update($id, UpdateRequest $request)
    {
        try {

            $data = $this->repository->update($id, $request->validated());
            return response()->json([
                "message" => "Data updated successfully !!",
                "data" => $data
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(
                [
                    "error" => "Item Not Found !!"
                ],
                404
            );
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);

        }
    }

    public function show($id)
    {
        try {

            $data = $this->repository->show($id);
            return response()->json([
                "message" => "Data retrieved successfully !!",
                "data" => $data
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(
                [
                    "error" => "Item Not Found !!"
                ],
                404
            );
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (Exception $e) {


            return response()->json(["error" => "An unexpected error occurred !!"], 500);

        }
    }

    public function destroy($id)
    {
        try {

            $data = $this->repository->delete($id);
            return response()->json([
                "message" => "Data deleted successfully !!",
                "data" => $data
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(
                [
                    "error" => "Item Not Found !!"
                ],
                404
            );
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (Exception $e) {


            return response()->json(["error" => "An unexpected error occurred !"], 500);

        }
    }
}
