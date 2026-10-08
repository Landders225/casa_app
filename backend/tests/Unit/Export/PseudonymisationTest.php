<?php

namespace Tests\Unit\Export;

use App\Domain\Export\Pseudonymisation;
use Illuminate\Support\Str;
use Tests\TestCase;

class PseudonymisationTest extends TestCase
{
    public function test_meme_candidature_id_donne_toujours_le_meme_id_pseudonyme(): void
    {
        $id = (string) Str::uuid();

        $this->assertSame(Pseudonymisation::idPseudonyme($id), Pseudonymisation::idPseudonyme($id));
    }

    public function test_deux_candidatures_differentes_donnent_des_id_pseudonymes_differents(): void
    {
        $a = Pseudonymisation::idPseudonyme((string) Str::uuid());
        $b = Pseudonymisation::idPseudonyme((string) Str::uuid());

        $this->assertNotSame($a, $b);
    }

    public function test_id_pseudonyme_est_prefixe_et_ne_contient_pas_l_uuid_source(): void
    {
        $id = (string) Str::uuid();
        $pseudonyme = Pseudonymisation::idPseudonyme($id);

        $this->assertStringStartsWith('C-', $pseudonyme);
        $this->assertStringNotContainsString($id, $pseudonyme);
    }

    public function test_id_pseudonyme_depend_de_la_cle_applicative(): void
    {
        $id = (string) Str::uuid();
        $avecCleActuelle = Pseudonymisation::idPseudonyme($id);

        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $avecAutreCle = Pseudonymisation::idPseudonyme($id);

        $this->assertNotSame($avecCleActuelle, $avecAutreCle);
    }
}
