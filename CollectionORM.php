<?php
namespace Asgard\Orm;

/**
 * ORM for related entities.
 * @author Michel Hognerud <michel@hognerud.com>
 */
class CollectionORM extends ORM implements CollectionORMInterface {
	/**
	 * Parent entity.
	 * @var \Asgard\Entity\Entity
	 */
	protected $parent;
	/**
	 * Relation instance.
	 * @var EntityRelation
	 */
	protected $relation;

	/**
	 * Constructor.
	 * @param \Asgard\Entity\Entity $entity            $entity
	 * @param string                                   $relation_name
	 * @param DataMapperInterface                      $datamapper
	 * @param string                                   $locale        default locale
	 * @param string                                   $prefix        tables prefix
	 * @param \Asgard\Common\PaginatorFactoryInterface $paginatorFactory
	 */
	public function __construct(\Asgard\Entity\Entity $entity, $relationName, DataMapperInterface $dataMapper, $locale=null, $prefix=null, \Asgard\Common\PaginatorFactoryInterface $paginatorFactory=null) {
		$this->parent = $entity;

		$this->relation = $dataMapper->relation($entity->getDefinition(), $relationName);

		parent::__construct($this->relation->getTargetDefinition(), $dataMapper, $locale, $prefix, $paginatorFactory);

		$reverseRelation = $this->relation->reverse();
		if($reverseRelation->get('polymorphic')) {
			$reverseRelation = clone($reverseRelation);
			$reverseRelation->setTargetDefinition($entity->getDefinition());
		}
		$this->joinToEntity($reverseRelation, $entity);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sync($ids, $force=false) {
		if(!$ids)
			$ids = [];
		if(!is_array($ids))
			$ids = [$ids];
		foreach($ids as $k=>$v) {
			if($v instanceof \Asgard\Entity\Entity) {
				if($v->isNew())
					$this->dataMapper->save($v, null, $force);
				$ids[$k] = (int)$v->id;
			}
		}

		switch($this->relation->type()) {
			case 'hasMany':
				$relationEntityDefinition = $this->relation->getTargetDefinition();
				$link = $this->relation->getLink();
				$dal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->dataMapper->getTable($relationEntityDefinition));
				if($this->relation->reverse()->get('polymorphic'))
					$dal->where([$link => $this->parent->id, $this->relation->reverse()->getLinkType() => get_class($this->parent)])->update([$link => null, $linkType => null]);
				else
					$dal->where([$link => $this->parent->id])->update([$link => null]);

				if($ids) {
					$newDal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->dataMapper->getTable($relationEntityDefinition));
					$newDal->where('id IN ('.implode(', ', $ids).')');
					if($this->relation->reverse()->get('polymorphic'))
						$newDal->update([$link => $entity->id, $this->relation->reverse()->getLinkType() => get_class($this->parent)]);
					else
						$newDal->update([$link => $this->parent->id]);
				}
				break;
			case 'HMABT':
				$dal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->relation->getTable());
				$dal->where($this->relation->getLinkA(), $this->parent->id);
				if($this->relation->reverse()->get('polymorphic'))
					$dal->where($this->relation->reverse()->getLinkType(), get_class($this->parent));
				$dal->delete();

				if($ids) {
					$dal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->relation->getTable());
					$i = 1;
					foreach($ids as $id) {
						$params = [$this->relation->getLinkA() => $this->parent->id, $this->relation->getLinkB() => $id];
						if($this->relation->get('sortable'))
							$params[$this->relation->getPositionField()] = $i++;
						if($this->relation->reverse()->get('polymorphic'))
							$params[$this->relation->reverse()->getLinkType()] = get_class($this->parent);
						$dal->insert($params);
					}
				}
				break;
			default:
				throw new \Exception('Collection only works with hasMany and HMABT');
		}

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function add($ids) {
		if(!is_array($ids))
			$ids = [$ids];
		foreach($ids as $k=>$id)
			if($id instanceof \Asgard\Entity\Entity)
				$ids[$k] = (int)$id->id;

		switch($this->relation['type']) {
			case 'hasMany':
				$relationEntityDefinition = $this->relation->getTargetDefinition();
				$dal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->dataMapper->getTable($relationEntityDefinition));
				foreach($ids as $id)
					$dal->reset()->where(['id' => $id])->update([$this->relation->getLink() => $this->parent->id]);
				break;
			case 'HMABT':
				$dal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->relation['join_table']);
				$i = 1;
				foreach($ids as $id) {
					$dal->reset()->where([$this->relation->getLinkA() => $this->parent->id, $this->relation->getLinkB() => $id])->delete();
					if($this->relation->get('sortable'))
						$dal->insert([$this->relation->getLinkA() => $this->parent->id, $this->relation->getLinkB() => $id, $this->relation->getPositionField() => $i++]);
					else
						$dal->insert([$this->relation->getLinkA() => $this->parent->id, $this->relation->getLinkB() => $id]);
				}
				break;
			default:
				throw new \Exception('Collection only works with hasMany and HMABT');
		}

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function create(array $params=[]) {
		$new = $this->relation->getTargetDefinition()->make();
		switch($this->relation->type()) {
			case 'hasMany':
				$params[$this->relation->getLink()] = $this->parent->id;
				$this->dataMapper->save($new, $params);
				break;
			case 'HMABT':
				$this->dataMapper->save($new, $params);
				$this->add($new->id);
				break;
		}
		return $new;
	}

	/**
	 * {@inheritDoc}
	 */
	public function remove($ids) {
		if(!is_array($ids))
			$ids = [$ids];
		foreach($ids as $k=>$id) {
			if($id instanceof \Asgard\Entity\Entity)
				$ids[$k] = $id->id;
		}

		switch($this->relation->type()) {
			case 'hasMany':
				$relation_entity = $this->relation['entity'];
				$dal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->dataMapper->getTable($relation_entity));
				foreach($ids as $id)
					$dal->reset()->where(['id' => $id])->update([$this->relation->getLink() => 0]);
				break;
			case 'HMABT':
				$dal = new \Asgard\Db\DAL($this->dataMapper->getDB(), $this->relation->getTable());
				foreach($ids as $id)
					$dal->reset()->where([$this->relation->getLinkA() => $this->parent->id, $this->relation->getLinkB() => $id])->delete();
				break;
			default:
				throw new \Exception('Collection only works with hasMany and HMABT');
		}

		return $this;
	}
}
