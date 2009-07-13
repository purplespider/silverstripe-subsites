<?php
/**
 * Extension for the Group object to add subsites support
 *
 * @package subsites
 */
class GroupSubsites extends DataObjectDecorator {

	function extraStatics() {
		if(!method_exists('DataObjectDecorator', 'load_extra_statics')) {
			if($this->owner->class != 'Group') return null;
		}
		return array(
			'has_one' => array(
				'Subsite' => 'Subsite',
			),
		);
	}

	function updateCMSFields(&$fields) {

		if( $this->owner->SubsiteID == 0 || $this->owner->canEdit() ){
			$subsites = DataObject::get('Subsite');
			if ( $subsites && $subsites->exists() ) {
				$oldSubsiteID = Session::get('SubsiteID');
				foreach( $subsites as $subsite ) {
					Subsite::changeSubsite($subsite->ID);
					if ( !Permission::check('CL_Admin') ) { $subsites->remove( $subsite ) ; }
				}
				Subsite::changeSubsite($oldSubsiteID);

				$tab = $fields->findOrMakeTab(
					'Root.Subsites',
					_t('GroupSubsites.SECURITYTABTITLE', 'Subsites')
				);

				$dropdown = new DropdownField(
					'SubsiteID',
					_t('GroupSubsites.SECURITYACCESS', 'Limit CMS access to subsites', PR_MEDIUM, 'Dropdown listing existing subsites which this group has access to'),
					$subsites->toDropDownMap(),
					null,
					null,
					''
				);
				if ( $subsites->Count() == 1 ) $dropdown = $dropdown->transform(new ReadonlyTransformation()) ;

				$tab->push($dropdown) ;
			}
		}
	}

	/**
	 * If this group belongs to a subsite,
	 * append the subsites title to the group title
	 * to make it easy to distinguish in the tree-view
	 * of the security admin interface.
	 */
	function alternateTreeTitle() {
		if($this->owner->SubsiteID == 0) {
			return $this->owner->Title . ' <i>(global group)</i>';
		} else {
			return $this->owner->Title; // . ' <i>(' . $this->owner->Subsite()->Title . ')</i>';
		}
	}

	/**
	 * Update any requests to limit the results to the current site
	 */
	function augmentSQL(SQLQuery &$query) {
		if(Subsite::$disable_subsite_filter) return;

		// If you're querying by ID, ignore the sub-site - this is a bit ugly...
		if(!$query->where || (strpos($query->where[0], ".\"ID\" = ") === false && strpos($query->where[0], ".`ID` = ") === false && strpos($query->where[0], ".ID = ") === false)) {

			if($context = DataObject::context_obj()) $subsiteID = (int)$context->SubsiteID;
			else $subsiteID = (int)Subsite::currentSubsiteID();

			// The foreach is an ugly way of getting the first key :-)
			foreach($query->from as $tableName => $info) {
				$where = "`$tableName`.SubsiteID IN (0, $subsiteID)";
				if(defined('Database::USE_ANSI_SQL')) {
					$where = "\"$tableName\".\"SubsiteID\" IN (0, $subsiteID)";
				}
				
				$query->where[] = $where;
				break;
			}

			$query->orderby = 'SubsiteID' . ($query->orderby ? ', ' : '') . $query->orderby;
		}
	}

	function augmentBeforeWrite() {
		if(!is_numeric($this->owner->ID) && !$this->owner->SubsiteID) $this->owner->SubsiteID = Subsite::currentSubsiteID();
	}

	function alternateCanEdit() {
		// Check the CMS_ACCESS_SecurityAdmin privileges on the subsite that owns this group
		$oldSubsiteID = Session::get('SubsiteID');

		Subsite::changeSubsite($this->owner->SubsiteID) ;
		$access = Permission::check('CMS_ACCESS_SecurityAdmin');
		Subsite::changeSubsite($oldSubsiteID) ;

		return $access;
	}

	/**
	 * Create a duplicate of this group and save it to another subsite.
	 * The group and permissions will be duplicated, but not the members.
	 * @param $subsiteID int|Subsite The Subsite to copy to, or its ID
	 */
	public function duplicateToSubsite($subsiteID = null) {
		if(is_object($subsiteID)) {
			$subsite = $subsiteID;
			$subsiteID = $subsite->ID;
		} else {
			$subsite = DataObject::get_by_id('Subsite', $subsiteID);
		}

		$group = $this->owner->duplicate(false);

		$subsiteID = ($subsiteID ? $subsiteID : Subsite::currentSubsiteID());
		$group->SubsiteID = $subsiteID;
		$group->write();

		// Duplicate permissions
		$permissions = $this->owner->Permissions();
		foreach($permissions as $permission) {
			$newPerm = $permission->duplicate(false);
			$newPerm->GroupID = $group->ID;
			$newPerm->write();
		}

		return $group;
	}

}

?>