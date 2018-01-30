<?php

namespace Sunlight\Database;

use Sunlight\Database\Database as DB;

/**
 * Trida pro spravu stromove tabulky
 */
class TreeManager
{
    /** @var string */
    protected $table;
    /** @var string */
    protected $idColumn;
    /** @var string */
    protected $parentColumn;
    /** @var string */
    protected $levelColumn;
    /** @var string */
    protected $depthColumn;

    /**
     * @param string      $table        nazev tabulky (vcetne pripadneho prefixu, jsou-li treba)
     * @param string|null $idColumn     nazev sloupce pro id
     * @param string|null $parentColumn nazev sloupce pro nadrazeny uzel
     * @param string|null $levelColumn  nazev sloupce pro uroven
     * @param string|null $depthColumn  nazev sloupce pro hloubku
     */
    public function __construct($table, $idColumn = null, $parentColumn = null, $levelColumn = null, $depthColumn = null)
    {
        $this->table = $table;
        $this->idColumn = $idColumn ?: 'id';
        $this->parentColumn = $parentColumn ?: 'node_parent';
        $this->levelColumn = $levelColumn ?: 'node_level';
        $this->depthColumn = $depthColumn ?: 'node_depth';
    }

    /**
     * Zkontrolovat, zda je dany nadrazeny uzel platny pro dany uzel
     *
     * @param int      $nodeId       ID uzlu
     * @param int|null $parentNodeId ID nadrazeneho uzlu
     * @return bool
     */
    public function checkParent($nodeId, $parentNodeId)
    {
        if (
            $parentNodeId === null
            || $nodeId != $parentNodeId
                && !in_array($parentNodeId, $this->getChildren($nodeId, true))
        ) {
            return true;
        }

        return false;
    }

    /**
     * Vytvorit novy uzel
     *
     * @param array $data
     * @param bool  $refresh
     * @return int id noveho uzlu
     */
    public function create(array $data, $refresh = true)
    {
        if (array_key_exists($this->levelColumn, $data) || array_key_exists( $this->depthColumn, $data)) {
            throw new \InvalidArgumentException(sprintf('Columns "%s" and "%s" cannot be specified manually', $this->levelColumn, $this->depthColumn));
        }
        $data += array(
            $this->parentColumn => null,
            $this->levelColumn => 0,
            $this->depthColumn => 0,
        );

        $nodeId = DB::insert($this->table, $data, true);
        if ($refresh) {
            $this->doRefresh($nodeId);
        }

        return $nodeId;
    }

    /**
     * Aktualizovat data uzlu
     *
     * @param int   $nodeId
     * @param int   $parentNodeId
     * @param array $changeset
     * @param bool  $refresh
     * @return static
     */
    public function update($nodeId, $parentNodeId, array $changeset, $refresh = true)
    {
        if (array_key_exists($this->levelColumn, $changeset) || array_key_exists($this->depthColumn, $changeset)) {
            throw new \InvalidArgumentException(sprintf('Columns "%s" and "%s" cannnot be changed manually', $this->levelColumn, $this->depthColumn));
        }

        // kontrola rodice
        $hasNewParent = array_key_exists($this->parentColumn, $changeset);
        if ($hasNewParent) {
            $newParent = $changeset[$this->parentColumn];
            if (!$this->checkParent($nodeId, $newParent)) {
                throw new \RuntimeException(sprintf('Node "%s" is not a valid parent node for "%s"', $changeset[$this->parentColumn], $nodeId));
            }
        }

        // aktualizace
        DB::update($this->table, $this->idColumn . '=' . DB::val($nodeId), $changeset);

        // refresh
        if ($refresh && $hasNewParent) {
            $this->refreshOnParentUpdate($nodeId, $newParent, $parentNodeId);
        }

        return $this;
    }

    /**
     * Odstranit uzel
     *
     * @param int  $nodeId
     * @param bool $orphanRemoval
     * @return static
     */
    public function delete($nodeId, $orphanRemoval = true)
    {
        if ($orphanRemoval) {
            $children = $this->getChildren($nodeId);
            $this->deleteSet($this->idColumn, $children);
        }
        $rootNodeId = $this->getRoot($nodeId);
        DB::delete($this->table, $this->idColumn . '=' . DB::val($nodeId));
        if ($nodeId != $rootNodeId) {
            $this->doRefreshDepth($rootNodeId, true);
        }

        return $this;
    }

    /**
     * Odstranit vsechny potomky uzlu
     *
     * @param int $nodeId
     * @return static
     */
    public function purge($nodeId)
    {
        $this->deleteSet($this->idColumn, $this->getChildren($nodeId));
        $this->doRefreshDepth($nodeId);

        return $this;
    }

    /**
     * Obnovit urovne stromu
     *
     * @param int|null $nodeId
     * @return static
     */
    public function refresh($nodeId = null)
    {
        $this->doRefresh($nodeId);

        return $this;
    }

    /**
     * Obnovit urovne stromu dle zmeny stavu rodice existujiciho uzlu
     * Nedoslo-li ke zmene rodice, obnova nebude provedena.
     *
     * @param int      $nodeId
     * @param int|null $newParent
     * @param int|null $oldParent
     */
    public function refreshOnParentUpdate($nodeId, $newParent, $oldParent)
    {
        if ($oldParent != $newParent) {
            $this->doRefresh($nodeId);
            $this->doRefreshDepth($oldParent);
        }
    }

    /**
     * Odstranit osirele uzly
     *
     * @param bool $refresh
     * @return static
     */
    public function purgeOrphaned($refresh = true)
    {
        do {
            $orphaned = DB::query('SELECT n.' . $this->idColumn . ',n.' . $this->parentColumn . ' FROM `' . $this->table . '` n LEFT JOIN `' . $this->table . '` p ON(n.' . $this->parentColumn . '=p.' . $this->idColumn . ') WHERE n.' . $this->parentColumn . ' IS NOT NULL AND p.id IS NULL');
            $orphanedCount = DB::size($orphaned);
            while ($row = DB::row($orphaned)) {

                // odstranit potomky
                $this->deleteSet($this->idColumn, $this->getChildren($row[$this->idColumn], true));

                // odstranit osirely uzel a jeho prime potomky
                DB::delete($this->table, $this->idColumn . '=' . DB::val($row[$this->idColumn]) . ' OR ' . $this->parentColumn . '=' . DB::val($row[$this->parentColumn]));

            }
            DB::free($orphaned);
        } while ($orphanedCount > 0);

        if ($refresh) {
            $this->doRefresh(null);
        }

        return $this;
    }

    /**
     * Upravit potomky na zaklade dat rodicu
     *
     * @param array    $flatTree
     * @param mixed    $context         pocatecti kontext
     * @param callable $propagator      callback(context, current_node), mel by vratit pole se zmenami nebo null
     * @param callable $contextUpdater  callback(context, current_node, current_changeset), mel by vratit novy kontext nebo null
     * @param bool     $getChangesetMap vratit mapu zmen namisto volani {@see DB::updateSetMulti()}
     * @return array|null
     */
    public function propagate(array $flatTree, $context, $propagator, $contextUpdater, $getChangesetMap = false)
    {
        $stack = array();
        $contextLevel = 0;
        $changesetMap = array();

        foreach ($flatTree as $node) {
            // aktualizovat aktualni kontext
            while (!empty($stack) && $node[$this->levelColumn] < $contextLevel) {
                list($context, $contextLevel) = array_pop($stack);
            }
            
            // zavolat propagator aktualniho kontextu
            $changeset = call_user_func($propagator, $context, $node);
            if ($changeset !== null) {
                $changesetMap[$node[$this->idColumn]] = $changeset;
            }

            // zavolat aktualizator kontextu (pro potomky)
            $newContext = call_user_func($contextUpdater, $context, $node, $changeset);
            if ($newContext !== null) {
                $stack[] = array($context, $contextLevel);
                $context = $newContext;
                $contextLevel = $node[$this->levelColumn] + 1;
                $newContext = null;
            }
        }

        if ($getChangesetMap) {
            return $changesetMap;
        }

        DB::updateSetMulti($this->table, $this->idColumn, $changesetMap);
    }

    /**
     * Ziskat uroven uzlu dle jeho pozice ve stromu
     *
     * @param int|null $nodeId
     * @param array    &$parents
     * @return int
     */
    protected function getLevel($nodeId, &$parents = null)
    {
        $level = 0;
        $parents = array();
        if ($nodeId === null) {
            return 0;
        }
        do {
            $node = DB::queryRow('SELECT ' . $this->parentColumn . ' FROM `' . $this->table . '` WHERE ' . $this->idColumn . '=' . DB::val($nodeId));
            if ($node === false) {
                throw new \RuntimeException(sprintf('Node "%s" does not exist', $nodeId));
            }

            $hasParent = ($node[$this->parentColumn] !== null);
            if ($hasParent) {
                $nodeId = $node[$this->parentColumn];
                $parents[] = $nodeId;
                if (++$level > 200) {
                    throw new \RuntimeException(sprintf('Limit of 200 nesting levels reached near node "%s" - recursive database data?', $node[$this->parentColumn]));
                }
            }
        } while ($hasParent);

        return $level;
    }

    /**
     * Ziskat ID rodice uzlu
     *
     * @param int|null $nodeId
     * @return int|null
     */
    protected function getParent($nodeId)
    {
        $node = DB::queryRow('SELECT ' . $this->parentColumn . ' FROM `' . $this->table . '` WHERE ' . $this->idColumn . '=' . DB::val($nodeId));
        if ($node === false) {
            throw new \RuntimeException(sprintf('Node "%s" does not exist', $nodeId));
        }

        return $node[$this->parentColumn];
    }

    /**
     * Ziskat korenovy uzel pro dany uzel
     *
     * @param int $nodeId
     * @return int
     */
    protected function getRoot($nodeId)
    {
        $parents = array();
        $this->getLevel($nodeId, $parents);
        if (!empty($parents)) {
            return end($parents);
        }

        return $nodeId;
    }

    /**
     * Ziskat vsechny podrazene uzly (nestrukturovano)
     *
     * @param int  $nodeId
     * @param bool $emptyArrayOnFailure
     * @return array
     */
    protected function getChildren($nodeId, $emptyArrayOnFailure = false)
    {
        // zjistit hloubku uzlu
        $node = DB::queryRow('SELECT ' . $this->depthColumn . ' FROM `' . $this->table . '` WHERE id=' . DB::val($nodeId));
        if ($node === false) {
            if ($emptyArrayOnFailure) {
                return array();
            }
            throw new \RuntimeException(sprintf('Node "%s" does not exist', $nodeId));
        }
        if ($node[$this->depthColumn] == 0) {
            // nulova hloubka
            return array();
        }

        // sestavit dotaz
        $sql = 'SELECT ';
        for ($i = 0; $i < $node[$this->depthColumn]; ++$i) {
            if ($i !== 0) {
                $sql .= ',';
            }
            $sql .= 'n' . $i . '.id';
        }

        $sql .= ' FROM `' . $this->table . '` r';
        $parentAlias = 'r';
        for ($i = 0; $i < $node[$this->depthColumn]; ++$i) {
            $nodeAlias = 'n' . $i;
            $sql .= sprintf(
                ' LEFT OUTER JOIN `%s` %s ON(%2$s.%s=%s.%s)',
                $this->table,
                $nodeAlias,
                $this->parentColumn,
                $parentAlias,
                $this->idColumn
            );
            $parentAlias = $nodeAlias;
        }
        $sql .= ' WHERE r.' . $this->idColumn . '=' . DB::val($nodeId);

        // nacist potomky
        $query = DB::query($sql);
        $childrenMap = array();
        while ($row = DB::rown($query)) {
            for ($i = 0; isset($row[$i]); ++$i) {
                $childrenMap[$row[$i]] = true;
            }
        }
        DB::free($query);

        return array_keys($childrenMap);
    }

    /**
     * Obnovit strukturove stavy v dane casti stromu
     *
     * @param int|null $currentNodeId
     */
    protected function doRefresh($currentNodeId)
    {
        // zjistit level a rodice aktualniho nodu
        $currentNodeParents = array();
        $currentNodeLevel = $this->getLevel($currentNodeId, $currentNodeParents);

        // pripravit frontu a level set
        $queue = array(
            array(
                $currentNodeId, // id uzlu
                $currentNodeLevel, // uroven uzlu
            ),
        );
        $levelset = array();
        if ($currentNodeId !== null) {
            $levelset[$currentNodeLevel] = array($currentNodeId => true);
        }

        // traverzovat frontu
        for ($i = 0; isset($queue[$i]); ++$i) {

            // traverzovat potomky aktualniho uzlu
            if ($queue[$i][0] !== null) {
                $childCondition = $this->parentColumn . '=' . DB::val($queue[$i][0]);
                $childrenLevel = $queue[$i][1] + 1;
            } else {
                $childCondition = $this->parentColumn . ' IS NULL';
                $childrenLevel = 0;
            }
            $children = DB::query('SELECT ' . $this->idColumn . ',' . $this->levelColumn . ' FROM `' . $this->table . '` WHERE ' . $childCondition);
            while ($child = DB::row($children)) {
                if ($childrenLevel != $child[$this->levelColumn]) {
                    if (isset($levelset[$childrenLevel][$child[$this->idColumn]])) {
                        throw new \RuntimeException(sprintf('Recursive dependency on node "%s"', $child[$this->idColumn]));
                    }
                    $levelset[$childrenLevel][$child[$this->idColumn]] = true;
                }
                $queue[] = array($child[$this->idColumn], $childrenLevel);
            }

            DB::free($children);
            unset($queue[$i]);

        }

        // aplikovat level set
        foreach ($levelset as $newLevel => $childrenMap) {
            $this->updateSet($this->idColumn, array_keys($childrenMap), array($this->levelColumn => $newLevel));
        }

        // aktualizovat hloubku cele vetve
        $topNodeId = end($currentNodeParents);
        if ($topNodeId === false) {
            $topNodeId = $currentNodeId;
        }
        $this->doRefreshDepth($topNodeId, true);
    }

    /**
     * Obnovit stav hloubky v cele vetvi
     *
     * @param int|null $currentNodeId
     * @param bool|null $isRootNode
     */
    protected function doRefreshDepth($currentNodeId, $isRootNode = null)
    {
        // zjistit korenovy uzel
        $rootNodeId = $currentNodeId;
        if ($isRootNode !== true && $currentNodeId !== null) {
            $rootNodeId = $this->getRoot($currentNodeId);
        }

        // pripravit frontu a depth mapu
        $queue = array(
            array(
                $rootNodeId, // id uzlu
                0, // uroven uzlu
                array(), // seznam nadrazenych uzlu
            ),
        );
        $depthmap = array();

        // traverzovat frontu
        for ($i = 0; isset($queue[$i]); ++$i) {

            // vyhledat potomky
            if ($queue[$i][0] !== null) {
                $childCondition = $this->parentColumn . '=' . DB::val($queue[$i][0]);
            } else {
                $childCondition = $this->parentColumn . ' IS NULL';
            }
            $children = DB::query($s = 'SELECT ' . $this->idColumn . ',' . $this->depthColumn . ' FROM `' . $this->table . '` WHERE ' . $childCondition);
            if (DB::size($children) > 0) {
                // uzel ma potomky, pridat do fronty
                if ($queue[$i][0] !== null) {
                    $childParents = array_merge(array($queue[$i][0]), $queue[$i][2]);
                } else {
                    $childParents = array();
                }

                while ($child = DB::row($children)) {
                    $queue[] = array($child[$this->idColumn], $child[$this->depthColumn], $childParents);
                }
            }
            DB::free($children);

            // aktualizovat urovne nadrazenych uzlu
            if ($queue[$i][0] !== null && !isset($depthmap[$queue[$i][0]])) {
                $depthmap[$queue[$i][0]] = 0;
            }
            for ($j = 0; isset($queue[$i][2][$j]); ++$j) {
                $currentDepth = $j + 1;
                if (!isset($depthmap[$queue[$i][2][$j]]) || $depthmap[$queue[$i][2][$j]] < $currentDepth) {
                    $depthmap[$queue[$i][2][$j]] = $currentDepth;
                }
            }
            unset($queue[$i]);

        }

        // konvertovat depth mapu na sety
        $depthsets = array();
        foreach ($depthmap as $nodeId => $newDepth) {
            $depthsets[$newDepth][] = $nodeId;
        }

        // aplikovat depth sety
        foreach ($depthsets as $newDepth => $nodeIds) {
            $this->updateSet($this->idColumn, $nodeIds, array($this->depthColumn => $newDepth));
        }
    }

    /**
     * Aktualizovat set dat v tabulce
     *
     * @param string $column
     * @param array  $set
     * @param array  $changeset
     * @param int    $maxPerQuery
     */
    protected function updateSet($column, array $set, array $changeset, $maxPerQuery = 100)
    {
        DB::updateSet($this->table, $column, $set, $changeset, $maxPerQuery);
    }

    /**
     * Odstranit set dat z tabulky
     *
     * @param string $column
     * @param array  $set
     * @param int    $maxPerQuery
     */
    protected function deleteSet($column, array $set, $maxPerQuery = 100)
    {
        DB::deleteSet($this->table, $column, $set, $maxPerQuery);
    }
}
