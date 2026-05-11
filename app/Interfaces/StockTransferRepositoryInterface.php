<?php

namespace App\Interfaces;

interface StockTransferRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);

    public function show($id, $branchID);

    public function delete($id);
}
?>