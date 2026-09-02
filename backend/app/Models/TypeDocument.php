<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CASA — référentiel des 6 types de pièces du dossier, table `type_document`
 * (docs/mld.md §3). Clé métier = `code`.
 */
class TypeDocument extends Model
{
    protected $table = 'type_document';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['code', 'libelle'];
}
