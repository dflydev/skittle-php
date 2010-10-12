<?php
/**
 * Helper Mapping Interface.
 * @package skittle
 */

/**
 * Helper Mapping Interface.
 *
 * The Helper Mapping maps helper names to helper objects. Any given
 * Helper Mapping must be aware of the names of the helper objects
 * it is responsible for.
 * @package skittle
 */
interface skittle_IHelperMapping {

    /**
     * Returns the helper object associated with the specified name.
     * @param string $name
     * @return object|null The helper object whose name is $name
     */
	public function getHelper($name);

    /**
     * Returns the names of the helper objects.
     * @return array The helper object names
     */
	public function getHelperNames();

}

?>
