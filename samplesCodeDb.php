<?php

namespace app\models;

use app\Db;

class Service
{
    public function getTariffs(int $userId, int $serviceId): array
    {
        $query = 'SELECT tarif_id FROM services WHERE ID = ? and user_id = ?';
        $tariffId = Db::fetchAll($query, [$serviceId, $userId])[0]['tarif_id'] ?? null;
        if (!$tariffId) {
            return [];
        }

        $query = 'SELECT * FROM tarifs WHERE tarif_group_id = ?';
        $data = Db::fetchAll($query, [$tariffId]);
        if (!$data) {
            return [];
        }

        $tariffs = [
            'title'  => null,
            'link'   => null,
            'speed'  => null,
            'tarifs' => [],
        ];

        $now = time();
        foreach ($data as $tariff) {
            if ($tariffId == $tariff['ID']) {
                $tariffs['title'] = $tariff['title'];
                $tariffs['link']  = $tariff['link'];
                $tariffs['speed'] = (float) $tariff['speed'];
            }

            $newPayday = strtotime(sprintf(
                '%d-%d-%d + %d months',
                date('Y', $now),
                date('m', $now),
                date('d', $now),
                $tariff['pay_period']
            )) . '+0300';
            $tariffs['tarifs'][] = [
                'ID'         => (int) $tariff['ID'],
                'title'      => $tariff['title'],
                'price'      => (float) $tariff['price'],
                'pay_period' => $tariff['pay_period'],
                'new_payday' => $newPayday,
                'speed'      => (float) $tariff['speed'],
            ];
        }

        return $tariffs;
    }

    public function setPayday(int $userId, int $serviceId, int $tariffId): bool
    {
        $payday = date('Y-m-d');
        $query = 'UPDATE services SET payday = ? WHERE ID = ? and user_id = ? and tarif_id = ?';
        Db::rowCount($query, [$payday, $serviceId, $userId, $tariffId]);

        $query = 'SELECT payday FROM services WHERE ID = ? and user_id = ? and tarif_id = ? and payday = ?';
        return Db::rowCount($query, [$serviceId, $userId, $tariffId, $payday]);
    }
}

class EbayAPI
{
    const EXCLUDE_REASON_MINUS_WORDS = 'minusWords';
    const EXCLUDE_REASON_KEYWORDS    = 'keywords';
    const EXCLUDE_REASON_CONDITION   = 'condition';
    const EXCLUDE_REASON_CATEGORY    = 'category';
    const EXCLUDE_REASON_AUCTION     = 'auction';
    const EXCLUDE_REASONS = [
        self::EXCLUDE_REASON_MINUS_WORDS => 'Title has minus word',
        self::EXCLUDE_REASON_KEYWORDS    => 'Title hasn\'t keywords',
        self::EXCLUDE_REASON_CONDITION   => 'Title has mistake condition',
        self::EXCLUDE_REASON_CATEGORY    => 'Item has excluded categoryId',
    ];

    /** @var string[] */
    private $minusWords;

    public function isItemListingTypeAuction(array $item): bool
    {
        return 'Auction' === $this->getItemListingType($item);
    }

    public function getItemTitle(array $item): string
    {
        return (string) $item['title'][0];
    }

    public function isTitleValidByMinusWords(string $title): bool
    {
        if (!$this->minusWords) {
            return true;
        }
        $regExp = '/('.implode('|', $this->minusWords).')/i';
        return preg_match($regExp, $title);
    }

    private function addExcludedItem(array $item, string $reasonKey): self
    {
        $itemId = $this->getItemId($item);
        $this->excludedItems[$itemId]['reasons'][$reasonKey] = static::EXCLUDE_REASONS[$reasonKey] ?? $reasonKey;
        $this->excludedItems[$itemId]['item'] = $item;
        return $this;
    }

    public function addItems(array $items): self
    {
        foreach ($items as $item) {
            $title = $this->getItemTitle($item);
            if (!$this->isTitleValidByMinusWords($title)) {
                $this->addExcludedItem($item, static::EXCLUDE_REASON_MINUS_WORDS);
            }
            if (!$this->isTitleValidByKeywords($title)) {
                $this->addExcludedItem($item, static::EXCLUDE_REASON_KEYWORDS);
            }
            if (!$this->isTitleValidByCondition($title)) {
                $this->addExcludedItem($item, static::EXCLUDE_REASON_CONDITION);
            }
            if (!$this->isCategoryValid($this->getItemCategoryId($item))) {
                $this->addExcludedItem($item, static::EXCLUDE_REASON_CATEGORY);
            }
            if ($this->isItemListingTypeAuction($item)) {
                $this->addExcludedItem($item, static::EXCLUDE_REASON_AUCTION);
            }
            if (!$this->isItemExcluded($item)) {
                $this->items[] = $item;
            }
        }
        return $this;
    }

    public function buildItemFiltersForRequest(array $filters): array
    {
        if (!$filters) {
            return [];
        }

        $i = -1;
        $itemFilters = [];
        foreach ($filters as $name => $values) {
            if (!$values) {
                continue;
            }

            $i++;
            $itemFilters['itemFilter('.$i.').name'] = $name;
            if (!is_array($values)) {
                $itemFilters['itemFilter('.$i.').value'] = $values;
                continue;
            }

            $j = 0;
            foreach ($values as $value) {
                $itemFilters['itemFilter('.$i.').value('.$j++.')'] = $value;
            }
        }

        return $itemFilters;
    }
}

namespace app;

use PDO;

class Db
{
    private static $dbh;

    protected static function getDbh(): PDO
    {
        if (static::$dbh) {
            return static::$dbh;
        }

        $dsn = sprintf('mysql:dbname=%s;host=%s;charset=utf8', DB_NAME, DB_HOST);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        static::$dbh = new PDO($dsn, DB_USER, DB_PASSWORD, $options);

        return static::$dbh;
    }

    public static function fetchAll(string $query, array $data = []): array
    {
        if (!$data) {
            return static::getDbh()->query($query)->fetchAll();
        }
        $stm = static::getDbh()->prepare($query);
        $stm->execute($data);
        return $stm->fetchAll();
    }

    public static function rowCount(string $query, array $data = []): int
    {
        if (!$data) {
            return static::getDbh()->query($query)->rowCount();
        }
        $stm = static::getDbh()->prepare($query);
        $stm->execute($data);

        return $stm->rowCount();
    }
}
