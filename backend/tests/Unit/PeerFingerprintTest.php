<?php

namespace Tests\Unit;

use App\Mail\PeerFingerprint;
use Tests\TestCase;

class PeerFingerprintTest extends TestCase
{
    public function test_chaine_vide_renvoie_null(): void
    {
        $this->assertNull(PeerFingerprint::normalize(''));
    }

    public function test_chaine_blanche_renvoie_null(): void
    {
        $this->assertNull(PeerFingerprint::normalize('   '));
    }

    public function test_format_production_avec_deux_points_et_majuscules(): void
    {
        $brut = 'E6:6F:EE:C9:0F:FB:97:4E:71:A0:01:DB:8A:21:85:3A:DB:FE:7D:F1:39:ED:F3:42:B2:3B:61:B0:95:67:A6:82';

        $this->assertSame(
            ['sha256' => 'e66feec90ffb974e71a001db8a21853adbfe7df139edf342b23b61b09567a682'],
            PeerFingerprint::normalize($brut)
        );
    }

    public function test_sans_deux_points_deja_minuscule(): void
    {
        $hex = 'e66feec90ffb974e71a001db8a21853adbfe7df139edf342b23b61b09567a682';

        $this->assertSame(['sha256' => $hex], PeerFingerprint::normalize($hex));
    }

    public function test_espaces_de_bord_ignores(): void
    {
        $hex = 'e66feec90ffb974e71a001db8a21853adbfe7df139edf342b23b61b09567a682';

        $this->assertSame(['sha256' => $hex], PeerFingerprint::normalize("  $hex  "));
    }

    public function test_casse_mixte(): void
    {
        $this->assertSame(
            ['sha256' => 'abcdef'],
            PeerFingerprint::normalize('aBcDeF')
        );
    }
}
