<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Entity\Market;
use PHPUnit\Framework\TestCase;

class AlertMarketAssociationTest extends TestCase
{
    public function testAlertCanTrackMultipleMarketsWithPrincipalMarket(): void
    {
        $alert = new Alert();

        $benin = new Market();
        $benin->setCodeIso3('BEN');
        $benin->setNom('Bénin');

        $nigeria = new Market();
        $nigeria->setCodeIso3('NGA');
        $nigeria->setNom('Nigeria');

        $alert->setMarket($benin);
        $alert->addMarketAssociation($benin, 'principal', 1);
        $alert->addMarketAssociation($nigeria, 'associe', 2);

        $this->assertSame($benin, $alert->getPrincipalMarket());
        $this->assertCount(2, $alert->getAlertMarkets());
        $this->assertSame(['BEN', 'NGA'], array_map(
            fn ($link) => $link->getMarket()->getCodeIso3(),
            $alert->getAlertMarkets()->toArray()
        ));
    }
}
