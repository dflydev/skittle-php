<?php
/**
 * Skittle.
 * @package skittle
 */

require_once('skittle_IHelperMapping.php');
require_once('skittle_IResourceLocator.php');

/**
 * Skittle.
 *
 * Skittle is a lightweight pure PHP template inclusion library. Its primary goal is to
 * allow for the easy inclusion of template fragments into other templates.
 *
 * <code>
 * <!-- A template fragment that had an array of items passed as $items -->
 * <table>
 * <?php foreach ( $items as $item ) { ?>
 *     <tr>
 *         <td><?php $s->inc('item.php', array('thisItem' => $item)); ?></td>
 *     </tr>
 * <?php } ?>
 * </code>
 *
 * <code>
 * <!-- An example of what item.php might look like             -->
 * <!-- since 'thisItem' was specified in the inc line above,   -->
 * <!-- this template now has access to $thisItem.              -->
 * <div class="item">
 * <span class="title"><?php $s->p($thisItem['title']); ?></span>
 * <span class="author"><?php $s->p($thisItem['author']); ?></span>
 * <span class="body"><?php $s->p($thisItem['body']); ?></span>
 * </div>
 * </code>
 *
 * This same item.php template would be usable from another template that has
 * access to another model structure that looks like $thisItem.
 *
 * <code>
 * <?php
 * $myItem = array(
 *     'title' => 'Hello World',
 *     'author' => 'Beau Simensen',
 *     'body' => 'Yes, World, Hello indeed.'
 * );
 * ?>
 * <div class="selectedItem">
 * <?php $s->inc('item.php', array('thisItem' => $myItem)); ?>
 * </div>
 * </code>
 *
 * @package skittle
 */
class skittle_Skittle {

    /**
     * Data in use at the current include level.
     * @var array
     */
    protected $currentData = null;

    /**
     * Data exported at the current include level.
     * @var array
     */
    protected $currentExportedData = null;

    /**
     * Tracks the data at each include level.
     * @var array
     */
    protected $storedData = array();

    /**
     * Tracks the exported data at each include level.
     * @var array
     */
    protected $storedExportedData = array();

    /**
     * Array of {@link skittle_IHelperMapping} instances.
     * @var array
     */
    protected $helperMappings = array();

    /**
     * Cache of currently loaded helpers.
     * @var array
     */
    protected $helpers = array();
    
    /**
     * Resource Locator
     * @var skittle_IResourceLocator
     */
    protected $resourceLocator;

    /**
     * Constructor.
     * @param mixed $resourceLocator List of directories or a skittle Resource Locator.
     */
    public function __construct($resourceLocator = null) {
        
        if ( $resourceLocator === null ) {
            require_once('skittle_ClasspathResourceLocator.php');
            $resourceLocator = new skittle_ClasspathResourceLocator();
            //
        } elseif ($resourceLocator instanceof skittle_IResourceLocator) {
            // noop
        } elseif ( ! is_object($resourceLocator) ) {
            require_once('skittle_PathResourceLocator.php');
            $resourceLocator = new skittle_PathResourceLocator(null, $resourceLocator);
        }
        
        $this->resourceLocator = $resourceLocator;
        
    }

    /**
     * Prints a shell include.
     *
     * If it is desired to have a shell wrap content, us this method. The shell
     * template will be expected to print out (likely raw) the variable
     * $renderedBody.
     *
     * <code>
     * <html>
     * <head><title>Sample</title></head>
     * <body>
     * <div id="content"><?php print $renderedBody; ?></div>
     * </body>
     * </html>
     * </code>
     *
     * @deprecated
     * @see stringShellInc()
     * @param string $shell Shell template name
     * @param string $body Body template name
     */
    public function shellInc() {
        $this->deprecated();
        $args = func_get_args();
        print call_user_func_array(array($this, 'stringShellInc'), $args);
    }

    /**
     * Wrap a shell around a body template.
     *
     * If it is desired to have a shell wrap content, us this method. The shell
     * template will be expected to print out (likely raw) the variable
     * $renderedBody.
     *
     * This method returns a string and is called by {@link shellInc()}.
     * 
     * @deprecated
     * @param string $shell Shell template name
     * @param string $body Body template name
     * @return string Rendered shell and body
     */
    public function stringShellInc() {
        
        $this->deprecated();

        $args = func_get_args();

        $shell = array_shift($args);
        $body = array_shift($args);

        $model = null;

        if ( count($args) > 0 and is_array($args[0]) ) {
            // Make sure that we do not do any out of bounds stuff
            // with our args array.
            $model = $args[0];
        }

        // Render the body into a string.
        $renderedBody = $this->stringInc($body, $model);

        // Add our rendered body to the model.
        $model['renderedBody'] = $renderedBody;

        // Render the shell to a string and pass that back.
        return $this->stringInc($shell, $model);

    }

    /**
     * Render a template.
     *
     * Includes a template. The model data passed will be extracted into
     * variables that the template can access. For example, given an
     * include that looks like:
     *
     * <code>
     * $s->inc('path/to/template.php', array('hello' => 'world'));
     * </code>
     *
     * The template path/to/template.php will be able to reference
     * a $hello variable.
     *
     * <code>
     * Hello there, <?php $s->p($hello); ?>
     * </code>
     *
     * This method renders a template and prints it inline.
     *
     * @param string $path Path to template to render
     * @param array $input Model data
     * @see stringInc()
     */
    public function inc() {
        $args = func_get_args();
        print call_user_func_array(array($this, 'stringInc'), $args);
    }

    /**
     * Render a template into a string.
     *
     * This method returns the rendered template as a string.
     *
     * @param string $path Path to template to render
     * @param array $input Model data
     * @see inc()
     */
    public function stringInc($path, $input = null) {

        // The data always starts out containing our helpers.
        $d = $this->helpers;

        // $s is always our Skittle instance ($this).
        $s = $this;

        $args = func_get_args();

        // Why do we support this? I cannot remember. :)
        if ( count($args) == 1 and is_array($args[0]) )
            $args = $args[0];

        // Path is always the first argument.
        $path = array_shift($args);

        if ( $this->currentData !== null ) {
            // If we have current data, it should be merged into our data.
            $d = array_merge($d, $this->currentData);
        }

        foreach ( $args as $data ) {
            // Assume that all remaining arguments are arrays that contain
            // additional model data.
            $d = array_merge($d, $data);
        }

        // Get the real path to the template.
        $realPath = $this->resourceLocator->find($path);

        if ( $realPath and file_exists($realPath) ) {

            if ( $this->currentExportedData === null ) $this->currentExportedData = array();

            // Remember our current data and our current exported data locally
            // so that we can restore these after we are finished rendering the
            // requested template.
            $lastData = $this->currentData;
            $lastExportedData = $this->currentExportedData;

            // The current values should now reflect our current model
            // data. We should also add this to our stack of stored data.
            $this->storedData[] = $this->currentData = $d;
            $this->storedExportedData[] = $this->currentExportedData;

            if ( $lastExportedData !== null ) {
                // If anything was exported at the previous level, we should
                // merge it into our data at this point. We do this so that
                // this exported data does not taint our current/last data.
                $d = array_merge($d, $lastExportedData);
            }

            // Extract our data into the symbol table.
            extract($d, EXTR_PREFIX_SAME, 'skittle');

            ob_start();

            $_____skittle_buffer = '';

            include($realPath);

            $_____skittle_buffer .= ob_get_contents();

            ob_end_clean();

            // Get rid of the stored data.
            array_pop($this->storedData);
            array_pop($this->storedExportedData);

            // Restore our data.
            $this->currentData = $lastData;
            $this->currentExportedData = $lastExportedData;

            // Clean up the skittle buffer. PHP has a weird habit of adding extra trailing
            // newlines that are not always desired.
            return preg_replace('/[\r\n]$/s', '', $_____skittle_buffer);

        } else {
            // If the include is not found, return an HTML comment so that there is
            // some debugging possible.
            // TODO This is maybe not the best way to handle this. An exception or an
            // error_log call may be more appropriate. Not all output will be HTML and
            // this could do more harm than good.
            return "<!-- include '$path' not found -->\n";
        }

    }

    /**
     * Add a helper.
     *
     * Helpers need to be added before they can be used in a template.
     *
     * <code>
     * $s->addHelper('uri');
     * </code>
     *
     * This method allows a helper to be added by name, and optionally specify an alias
     * name for a specific helper. For example, if for some reason $uri is too long of a
     * name for a helper object, the $uri helper object can be exposed at the $u variable
     * by specifying 'u' for the variable name and 'uri' as the helper name.
     *
     * <code>
     * $s->addHelper('u', 'uri');
     * </code>
     *
     * @param string $variableName Variable name to be used
     * @param string $helperName Actual name of the helper if different from the variable name
     * @see getHelper()
     */
    public function addHelper($variableName, $helperName = null) {
        if ( $helperName === null ) $helperName = $variableName;
        $this->helpers[$variableName] = $this->getHelper($helperName);
    }

    /**
     * Add a {@link skittle_IHelperMapping} instance.
     * @param skittle_IHelperMapping $helperMappings A {@link skittle_IHelperMapping} instance
     */
    public function addHelperMapping($helperMapping) {
        return $this->addHelperMappings(array($helperMapping));
    }

    /**
     * Add multiple {@link skittle_IHelperMapping} instances.
     * @param array $helperMappings An array of {@link skittle_IHelperMapping} instances
     */
    public function addHelperMappings($helperMappings) {
        if ( ! is_array($helperMappings) ) {
            $helperMappings = array($helperMappings);
        }
        foreach ( $helperMappings as $helperMapping ) {
            $this->helperMappings[] = $helperMapping;
        }
    }

    /**
     * Add a {@link skittle_IHelperMapping} instance.
     *
     * This method is used internally to enforce that the object passed is actually
     * an instance of {@link skittle_IHelperMapping}.
     * @param skittle_IHelperMapping $helperMappings A {@link skittle_IHelperMapping} instance
     */
    private function addHelperMappingInternal(skittle_IHelperMapping $helperMapping) {
        $this->helperMappings[] = $helperMapping;
    }

    /**
     * Retrieve a helper.
     *
     * Retrieves a helper by name. If the helper object is passed, that is used. Otherwise,
     * it will load the helper object from the {@link $helpers} map.
     *
     * @param string $helperName Name of helper to retrieve
     * @param object $helperObject Helper object
     */
    protected function retrieveHelper($helperName, $helperObject = null) {
        if ( $helperObject === null ) {
            // It is assumed that this method will never be called unless
            // $helperName is known to exist in the helpers array.
            $helperObject = $this->helpers[$helperName];
        }
        return $helperObject;
    }
    
    /**
     * @deprecated
     * @param $helperName
     * @param $exceptionOnMissing
     */
    public function getHelper($helperName, $exceptionOnMissing = true) {
        return $this->deprecated()->helper($helperName, $exceptionOnMissing);
    }

    /**
     * Get a helper by name.
     *
     * If a helper has already been retrieved, it returns the cached instance.
     *
     * Otherwise, each of the helper mappings are queried until one returns something
     * other than null for the specified helper name.
     *
     * If no helper is found anywhere, one of two things can happen. If $exceptionOnMissing
     * is true (this is the default case), an exception is thrown. Otherwise, null is
     * returned.
     *
     * @param string $helperName Name of helper to get
     * @param boolean $exceptionOnMissing Throw an exception of the helper cannot be found
     */
    public function helper($helperName, $exceptionOnMissing = true) {
        if ( array_key_exists($helperName, $this->helpers) ) {
            // If this helper already exists, we can retrieve the helper.
            return $this->retrieveHelper($helperName);
        }
        foreach ( $this->helperMappings as $helperMapping ) {
            // Ask each helper mapping for this helper.
            $helper = $helperMapping->getHelper($helperName);
            if ( $helper !== null  ) {
                // If the helper was found, store it for us to use
                // later and then retrieve the helper.
                $this->helpers[$helperName] = $helper;
                return $this->retrieveHelper($helperName);
            }
        }
        if ( $exceptionOnMissing ) {
            throw new Exception('Could not locate helper named "' . $helperName . '"');
        }
        return null;
    }

    /**
     * Get all of the helpers.
     *
     * This method will query all of the helper mappings to get all of the available
     * helpers for each of them. This method is very heavy handed and should probably
     * be avoided, especially if there are some costly helpers to create.
     * @return array All helpers available from all of the helper mappings.
     */
    public function getHelpers() {
        $allHelpers = array();
        foreach ( $this->helperMappings as $helperMapping ) {
            foreach ( $helperMapping->getHelperNames() as $helperName ) {
                // TODO We might want to handle this differently so that naming
                // collisions are handled in a consistent way.
                $allHelpers[$helperName] = $this->retrieveHelper(
                    $helperName,
                    $helperMapping->getHelper($helperName)
                );
            }
        }
        return $allHelpers;
    }

    /**
     * Safely print a string.
     * @param string $string String to print
     * @param boolean $htmlSafe Should the string be made HTML safe?
     * @see g
     */
    public function p($string, $htmlSafe = null) {
        print $this->g($string, $htmlSafe);
    }

    /**
     * Get a string.
     * @param string $string String to get
     * @param boolean $htmlSafe Should the string be made HTML safe?
     * @see p
     */
    public function g($string, $htmlSafe = null) {
        if ( $htmlSafe === null or $htmlSafe ) $string = htmlspecialchars($string);
        return $string;
    }

    /**
     * Export a named value to current namespace.
     *
     * Used in the case where a value needs to be exported into the
     * template system to potentially be used by sub templates.
     *
     * $foo = $s->export('foo', 'Hello World!');
     *
     * @param string $name Name of value
     * @param mixed $value Actual value
     * @return mixed Value.
     */
    public function export($name, $value = null) {
        return $this->currentExportedData[$name] = $value;
    }

    /**
     * Used when a method has been deprecated
     * @return skittle_Skittle
     */
    private function deprecated() {
        return $this;
    }

}
