<?php

namespace Mothership\DAM\Repositories;

use Mothership\Core\Eloquent\Repository;
use Mothership\DAM\Models\AssetComments;

class AssetCommentsRepository extends Repository
{
    protected $assets = [];

    public function model(): string
    {
        return AssetComments::class;
    }

    public function create(array $data)
    {
        $asset = $this->model->create($data);

        return $asset;
    }

    public function update(array $data, $id, $asset = 'id')
    {
        $asset = $this->find($id);

        $asset->update($data);

        return $asset;
    }
}
