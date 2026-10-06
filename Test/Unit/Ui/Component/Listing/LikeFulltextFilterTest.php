<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Ui\Component\Listing;

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\OrderAttachments\Ui\Component\Listing\LikeFulltextFilter;
use PHPUnit\Framework\TestCase;

class LikeFulltextFilterTest extends TestCase
{
    private function filter(mixed $value): Filter
    {
        $filter = $this->createStub(Filter::class);
        $filter->method('getValue')->willReturn($value);

        return $filter;
    }

    private function collection(?string &$where, int $expectedWhereCalls = 1): AbstractDb
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn($c) => '`' . $c . '`');
        $connection->method('quoteInto')->willReturnCallback(
            static fn($text, $value) => str_replace('?', "'" . $value . "'", $text)
        );

        $select = $this->createMock(Select::class);
        $select->expects($this->exactly($expectedWhereCalls))->method('where')
            ->willReturnCallback(function ($cond) use (&$where, $select) {
                $where = $cond;
                return $select;
            });

        $collection = $this->createStub(AbstractDb::class);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getSelect')->willReturn($select);

        return $collection;
    }

    public function testBuildsOrConditionAcrossConfiguredColumns(): void
    {
        $where = null;
        $filter = new LikeFulltextFilter(['original_filename', 'customer_email', 42]);

        $filter->apply($this->collection($where), $this->filter('  report  '));

        $this->assertSame(
            "`original_filename` LIKE '%report%' OR `customer_email` LIKE '%report%'",
            $where
        );
    }

    public function testEscapesLikeWildcards(): void
    {
        $where = null;
        $filter = new LikeFulltextFilter(['original_filename']);

        $filter->apply($this->collection($where), $this->filter('50%_off'));

        $this->assertSame("`original_filename` LIKE '%50\\%\\_off%'", $where);
    }

    public function testLimitsSearchTermLength(): void
    {
        $where = null;
        $filter = new LikeFulltextFilter(['c']);

        $filter->apply($this->collection($where), $this->filter(str_repeat('x', 500)));

        $this->assertSame("`c` LIKE '%" . str_repeat('x', 200) . "%'", $where);
    }

    public function testEmptyOrNonScalarValueIsIgnored(): void
    {
        $where = null;
        $filter = new LikeFulltextFilter(['c']);

        $filter->apply($this->collection($where, 0), $this->filter('   '));
        $filter->apply($this->collection($where, 0), $this->filter(['a']));

        $this->assertNull($where);
    }

    public function testNoColumnsOrNonDbCollectionIsIgnored(): void
    {
        $where = null;
        (new LikeFulltextFilter([]))->apply($this->collection($where, 0), $this->filter('x'));

        $plain = $this->createMock(Collection::class);
        $plain->expects($this->never())->method($this->anything());
        (new LikeFulltextFilter(['c']))->apply($plain, $this->filter('x'));

        $this->assertNull($where);
    }
}
