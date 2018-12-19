<?php declare(strict_types=1);

namespace Phan\Language;

use AssertionError;
use ast\flags;
use Error;
use InvalidArgumentException;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Exception\EmptyFQSENException;
use Phan\Exception\FQSENException;
use Phan\Exception\InvalidFQSENException;
use Phan\Exception\IssueException;
use Phan\Exception\RecursionDepthException;
use Phan\Issue;
use Phan\Language\Element\Comment;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type\ArrayShapeType;
use Phan\Language\Type\ArrayType;
use Phan\Language\Type\BoolType;
use Phan\Language\Type\CallableDeclarationType;
use Phan\Language\Type\CallableType;
use Phan\Language\Type\ClosureDeclarationParameter;
use Phan\Language\Type\ClosureDeclarationType;
use Phan\Language\Type\ClosureType;
use Phan\Language\Type\FalseType;
use Phan\Language\Type\FloatType;
use Phan\Language\Type\FunctionLikeDeclarationType;
use Phan\Language\Type\GenericArrayType;
use Phan\Language\Type\GenericIterableType;
use Phan\Language\Type\GenericMultiArrayType;
use Phan\Language\Type\IntType;
use Phan\Language\Type\IterableType;
use Phan\Language\Type\LiteralIntType;
use Phan\Language\Type\LiteralStringType;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\NativeType;
use Phan\Language\Type\NullType;
use Phan\Language\Type\ObjectType;
use Phan\Language\Type\ResourceType;
use Phan\Language\Type\ScalarRawType;
use Phan\Language\Type\ScalarType;
use Phan\Language\Type\SelfType;
use Phan\Language\Type\StaticType;
use Phan\Language\Type\StringType;
use Phan\Language\Type\TemplateType;
use Phan\Language\Type\TrueType;
use Phan\Language\Type\VoidType;
use Phan\Library\None;
use Phan\Library\Option;
use Phan\Library\Some;
use Phan\Library\Tuple5;
use function count;
use function stripos;
use function strtolower;

/**
 * The base class for all of Phan's types.
 * A plain Type represents a class instance.
 * Separate subclasses exist for NativeType, ArrayType, ScalarType, TemplateType, etc.
 *
 * Types are immutable.
 *
 * @phan-file-suppress PhanPartialTypeMismatchArgumentInternal
 * phpcs:disable Generic.NamingConventions.UpperCaseConstantName
 */
class Type
{
    use \Phan\Memoize;

    /**
     * @var string
     * A legal type identifier (e.g. 'int' or 'DateTime')
     */
    const simple_type_regex =
        '(\??)\\\\?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?:\\\\[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)*';

    const simple_noncapturing_type_regex =
        '\\\\?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?:\\\\[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)*';

    /**
     * @var string
     * A legal type identifier (e.g. 'int' or 'DateTime')
     */
    const simple_type_regex_or_this =
        '(\??)([a-zA-Z_\x7f-\xff\\\][a-zA-Z0-9_\x7f-\xff\\\]*|\$this)';

    const shape_key_regex =
        '[-._a-zA-Z0-9\x7f-\xff]+\??';

    /**
     * A literal integer or string.
     *
     * Note that string literals can only contain a whitelist of characters.
     * NOTE: The / is escaped
     */
    const noncapturing_literal_regex =
        '\??(?:-?(?:0|[1-9][0-9]*)|\'(?:[- ,.\/?:;"!#$%^&*_+=a-zA-Z0-9_\x80-\xff]|\\\\(?:[\'\\\\]|x[0-9a-fA-F]{2}))*\')';
        // '\??(?:-?(?:0|[1-9][0-9]*)|\'(?:[a-zA-Z0-9_])*\')';

    /**
     * @var string
     * A legal array entry in an array shape (e.g. 'field:string[]')
     *
     * @suppress PhanUnreferencedPublicClassConstant
     */
    const array_shape_entry_regex_noncapturing =
        '(?:' . self::shape_key_regex . ')\s*:\s*(?:' . self::simple_noncapturing_type_regex . '=?)';

    /**
     * @var string
     * A legal type identifier matching a type optionally with a []
     * indicating that it's a generic typed array (e.g. 'int[]',
     * 'string' or 'Set<DateTime>' or 'array{field:string}')
     *
     * https://www.debuggex.com/ is useful for a visual description of these regexes
     */
    const type_regex =
        '('
        . '(?:\??\((?-1)(?:\|(?-1))*\)|'  // Recursion: "?(T)" or "(T)" with brackets. Also allow parsing (a|b) within brackets.
        . '(?:'
          . '\??(?:\\\\?Closure|callable)(\((?:[^()]|(?-1))*\))'  // `Closure(...)` can have matching pairs of () inside `...`, recursively
          . '(?:\s*:\s*'  // optional return type, can be ":T" or ":(T1|T2)" or ": ?(T1|T2)"
            . '(?:'
              . self::simple_noncapturing_type_regex . '|'  // Forbid ambiguity in `Closure():int[]` by disallowing complex return types without '()'. Always parse that as `(Closure():int)[]`.
              . '\((?-2)(?:\s*\|\s*(?-2))*\)'
            . ')'
          . ')?'
        . ')|'
        . self::noncapturing_literal_regex . '|'
        . '(' . self::simple_type_regex . ')'  // ?T or T.
        . '(?:'
          . '<'
            . '('
              . '(?-5)(?:\|(?-5))*'
              . '(\s*,\s*'
                . '(?-6)(?:\|(?-6))*'
              . ')*'
            . ')'
          . '>'
          . '|'
          . '\{('  // Expect either '{' or '<', after a word token.
            . '(?:' . self::shape_key_regex . '\s*:\s*(?-7)(?:\|(?-7))*=?)'  // {shape_key_regex:<type_regex>}
            . '(?:,\s*' . self::shape_key_regex . '\s*:\s*(?-7)(?:\|(?-7))*=?)*'  // {shape_key_regex:<type_regex>}
          . ')?\})?'
        . ')'
        . '(\[\])*'
      . ')';

    /**
     * @var string
     * A legal type identifier matching a type optionally with a []
     * indicating that it's a generic typed array (e.g. 'int[]' or '$this[]',
     * 'string' or 'Set<DateTime>' or 'array<int>' or 'array<int|string>')
     *
     * https://www.debuggex.com/ is useful for a visual description of these regexes
     */
    const type_regex_or_this =
        '('
        . '('
          . '(?:'
            . '\??\((?-1)(?:\|(?-1))*\)|'  // Recursion: "?(T)" or "(T)" with brackets. Also allow parsing (a|b) within brackets.
            . '(?:'
              . '\??(?:\\\\?Closure|callable)(\((?:[^()]|(?-1))*\))'  // `Closure(...)` can have matching pairs of () inside `...`, recursively
              . '(?:\s*:\s*'  // optional return type, can be ":T" or ":(T1|T2)"
                . '(?:'
                  . self::simple_noncapturing_type_regex . '|'  // Forbid ambiguity in `Closure():int[]` by disallowing complex return types without '()'. Always parse that as `(Closure():int)[]`.
                  . '\((?-2)(?:\s*\|\s*(?-2))*\)'  // Complicated return types can be placed within ().
                . ')'
              . ')?'
            . ')|'
            . self::noncapturing_literal_regex . '|'
            . '(' . self::simple_type_regex_or_this . ')'  // 3 patterns
            . '(?:<'
              . '('
                . '(?-6)(?:\|(?-6))*'  // We use relative references instead of named references so that more than one one type_regex can be used in a regex.
                . '(\s*,\s*'
                  . '(?-7)(?:\|(?-7))*'
                . ')*'
              . ')'
              . '>'
              . '|'
              . '(\{)('  // Expect either '{' or '<', after a word token. Match '{' to disambiguate 'array{}'
                . '(?:' . self::shape_key_regex . '\s*:\s*(?-9)(?:\|(?-9))*=?)'  // {shape_key_regex:<type_regex>}
                . '(?:,\s*' . self::shape_key_regex . '\s*:\s*(?-9)(?:\|(?-9))*=?)*'  // {shape_key_regex:<type_regex>}
              . ')?\})?'
            . ')'
          . '(\[\])*'
        . ')'
       . ')';

    /**
     * @var array<string,bool> - For checking if a string is an internal type. This is used for case-insensitive lookup.
     */
    const _internal_type_set = [
        'array'     => true,
        'bool'      => true,
        'callable'  => true,
        'false'     => true,
        'float'     => true,
        'int'       => true,
        'iterable'  => true,
        'mixed'     => true,
        'null'      => true,
        'object'    => true,
        'resource'  => true,
        'scalar'    => true,
        'static'    => true,
        'string'    => true,
        'true'      => true,
        'void'      => true,
    ];

    /**
     * These can currently be used in phpdoc but not real types.
     * This is a subset of self::_internal_type_set
     *
     * https://secure.php.net/manual/en/reserved.other-reserved-words.php
     * > The following list of words have had soft reservations placed on them.
     * > Whilst they may still be used as class, interface, and trait names (as well as in namespaces),
     * > usage of them is highly discouraged since they may be used in future versions of PHP.
     *
     * (numeric not supported yet)
     */
    const _soft_internal_type_set = [
        'false'     => true,
        'mixed'     => true,
        'object'    => true,
        'resource'  => true,
        'scalar'    => true,
        'true'      => true,
    ];

    // Distinguish between multiple ways types can be created.
    // e.g. integer and resource are phpdoc types, but they aren't actual types.

    /** For types created from a type in an AST node, e.g. `int $x` */
    const FROM_NODE = 0;

    /** For types copied from another type, e.g. `$x = $y` gets types from $y */
    const FROM_TYPE = 1;

    /** For types copied from phpdoc, e.g. `(at)param integer $x` */
    const FROM_PHPDOC = 2;

    /** To distinguish NativeType subclasses and classes with the same name. Overridden in subclasses */
    const KEY_PREFIX = '';

    /** To normalize combinations of union types */
    const _bit_false    = (1 << 0);
    const _bit_true     = (1 << 1);
    const _bit_bool_combination = self::_bit_false | self::_bit_true;
    const _bit_nullable = (1 << 2);

    /**
     * @var string
     * The namespace of this type such as '' (for internal types such as 'int')
     * or '\' or '\Phan\Language'
     */
    protected $namespace = null;

    /**
     * @var string
     * The name of this type such as 'int' or 'MyClass'
     */
    protected $name = '';

    /**
     * @var array<int,UnionType>
     * A possibly empty list of concrete types that
     * act as parameters to this type if it is a templated
     * type.
     */
    protected $template_parameter_type_list = [];

    /**
     * @var bool
     * True if this type is nullable, else false
     */
    protected $is_nullable = false;

    /**
     * @var array<string,Type> - Maps a key to a Type or subclass of Type
     */
    private static $canonical_object_map = [];

    /**
     * @param string $namespace
     * The (optional) namespace of the type such as '\'
     * or '\Phan\Language'.
     *
     * @param string $name
     * The name of the type such as 'int' or 'MyClass'
     *
     * @param array<int,UnionType> $template_parameter_type_list
     * A (possibly empty) list of template parameter types
     *
     * @param bool $is_nullable
     * True if this type can be null, false if it cannot
     * be null.
     */
    protected function __construct(
        string $namespace,
        string $name,
        $template_parameter_type_list,
        bool $is_nullable
    ) {
        $this->namespace = $namespace;
        $this->name = $name;
        $this->template_parameter_type_list = $template_parameter_type_list;
        $this->is_nullable = $is_nullable;
    }

    // Override two magic methods to ensure that Type isn't being cloned accidentally.
    // (It has previously been accidentally cloned in unit tests by phpunit (global_state helper),
    //  which saves and restores some static properties)

    /** @throws Error this should not be called accidentally */
    public function __wakeup()
    {
        debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        throw new Error("Cannot unserialize Type '$this'");
    }

    /** @throws Error this should not be called accidentally */
    public function __clone()
    {
        throw new Error("Cannot clone Type '$this'");
    }

    /**
     * @param string $namespace
     * The (optional) namespace of the type such as '\'
     * or '\Phan\Language'.
     *
     * @param string $type_name
     * The name of the type such as 'int' or 'MyClass'
     *
     * @param array<int,UnionType> $template_parameter_type_list
     * A (possibly empty) list of template parameter types
     *
     * @param bool $is_nullable
     * True if this type can be null, false if it cannot
     * be null.
     *
     * @param int $source
     * Type::FROM_NODE, Type::FROM_TYPE, or Type::FROM_PHPDOC
     * (E.g. outside of phpdoc, "integer" would be a class name)
     *
     * @return Type
     * A single canonical instance of the given type.
     *
     * @throws AssertionError if an unparsable string is passed in
     */
    protected static function make(
        string $namespace,
        string $type_name,
        array $template_parameter_type_list,
        bool $is_nullable,
        int $source
    ) : Type {

        $namespace = \trim($namespace);

        if ('\\' === $namespace && $source) {
            $type_name = self::canonicalNameFromName($type_name);
        }

        // If this looks like a generic type string, explicitly
        // make it as such
        $pos = \strrpos($type_name, '[]');
        if ($pos > 0) {
            return GenericArrayType::fromElementType(Type::make(
                $namespace,
                \substr($type_name, 0, $pos),
                $template_parameter_type_list,
                false,
                $source
            ), $is_nullable, GenericArrayType::KEY_MIXED);
        }

        if ($namespace === '') {
            throw new AssertionError("Namespace cannot be empty");
        }

        if ('\\' !== $namespace[0]) {
            throw new AssertionError("Namespace must be fully qualified");
        }

        if ($type_name === '') {
            throw new AssertionError("Type name cannot be empty");
        }

        if (\strpos($type_name, '|') !== false) {
            throw new AssertionError("Type name '$type_name' may not contain a pipe");
        }

        // Create a canonical representation of the
        // namespace and name
        if ('\\' === $namespace && $source === Type::FROM_PHPDOC) {
            $type_name = self::canonicalNameFromName($type_name);
        }

        // Make sure we only ever create exactly one
        // object for any unique type
        $key = ($is_nullable ? '?' : '') . static::KEY_PREFIX . $namespace . '\\' . $type_name;

        if ($template_parameter_type_list) {
            $key .= '<' . \implode(',', \array_map(function (UnionType $union_type) : string {
                return $union_type->__toString();
            }, $template_parameter_type_list)) . '>';
        }

        $key = strtolower($key);

        $value = self::$canonical_object_map[$key] ?? null;
        if (!$value) {
            if ($namespace === '\\' && $type_name === 'Closure') {
                $value = new ClosureType(
                    $namespace,
                    $type_name,
                    $template_parameter_type_list,
                    $is_nullable
                );
            } elseif ($namespace === '\\' && $type_name === 'callable') {
                $value = new CallableType(
                    $namespace,
                    $type_name,
                    $template_parameter_type_list,
                    $is_nullable
                );
            } else {
                $value = new static(
                    $namespace,
                    $type_name,
                    $template_parameter_type_list,
                    $is_nullable
                );
                // FIXME Phan warns that array<string,static> can't be assigned to array<string,Type>
                '@phan-var Type $value';
            }
            self::$canonical_object_map[$key] = $value;
        }
        return $value;
    }

    /**
     * Call this before forking and analysis phase, when in daemon mode.
     * This may hurt performance.
     *
     * It's important to clear asExpandedTypes(),
     * as the parent classes may have changed since the last parse attempt.
     *
     * This gets called immediately after the parse phase but before the analysis phase.
     *
     * @return void
     */
    public static function clearAllMemoizations()
    {
        // Clear anything that has memoized state
        foreach (self::$canonical_object_map as $type) {
            $type->memoizeFlushAll();
        }
    }


    /**
     * Constructs a type based on the input type and the provided mapping
     * from template type identifiers to concrete union types.
     *
     * @param Type $type
     * The base type of this generic type referencing a
     * generic class
     *
     * @param array<int,UnionType> $template_parameter_type_list
     * A map from a template type identifier to a
     * concrete union type
     */
    public static function fromType(
        Type $type,
        array $template_parameter_type_list
    ) : Type {
        return self::make(
            $type->getNamespace(),
            $type->getName(),
            $template_parameter_type_list,
            $type->is_nullable,
            Type::FROM_TYPE
        );
    }

    /**
     * If the $name is a reserved constant, then returns the NativeType for that name
     * Otherwise, this returns null.
     * @return Option<NativeType>
     */
    public static function fromReservedConstantName(string $name) : Option
    {
        static $lookup;
        if ($lookup === null) {
            $lookup = self::createReservedConstantNameLookup();
        }
        $result = $lookup[\strtoupper(\ltrim($name, '\\'))] ?? null;
        if (isset($result)) {
            return new Some($result);
        }
        return new None();
    }

    /**
     * @return array<string,NativeType> a map from the **uppercase** reserved constant name to the subclass of NativeType for that constant.
     * Uses the constants and types from https://secure.php.net/manual/en/reserved.constants.php
     */
    private static function createReservedConstantNameLookup() : array
    {
        $false  = FalseType::instance(false);
        // $float  = FloatType::instance(false);
        $int    = IntType::instance(false);
        $null   = NullType::instance(false);
        $string = StringType::instance(false);
        $true   = TrueType::instance(false);

        return [
            'PHP_VERSION'           => $string,
            'PHP_MAJOR_VERSION'     => $int,
            'PHP_MINOR_VERSION'     => $int,
            'PHP_RELEASE_VERSION'   => $int,
            'PHP_VERSION_ID'        => $int,
            'PHP_EXTRA_VERSION'     => $string,
            'PHP_ZTS'               => $int,
            'PHP_MAXPATHLEN'        => $int,
            'PHP_OS'                => $string,
            'PHP_OS_FAMILY'         => $string,
            'PHP_SAPI'              => $string,
            'PHP_EOL'               => $string,
            'PHP_INT_MAX'           => Type::fromObject(\PHP_INT_MAX),
            'PHP_INT_MIN'           => Type::fromObject(\PHP_INT_MIN),  // since 7.0.0
            'PHP_INT_SIZE'          => Type::fromObject(\PHP_INT_SIZE),  // since 7.0.0
            //'PHP_FLOAT_DIG'         => $int,  // since 7.2.0
            //'PHP_FLOAT_EPSILON'     => $float,  // since 7.2.0
            //'PHP_FLOAT_MIN'         => $int, // since 7.2.0
            //'PHP_FLOAT_MAX'         => $int, // since 7.2.0
            'DEFAULT_INCLUDE_PATH'  => $string,
            'PEAR_INSTALL_DIR'      => $string,
            'PHP_EXTENSION_DIR'     => $string,
            'PEAR_EXTENSION_DIR'    => $string,
            'PHP_PREFIX'            => $string,
            'PHP_BINDIR'            => $string,
            'PHP_BINARY'            => $string,
            'PHP_MANDIR'            => $string,
            'PHP_LIBDIR'            => $string,
            'PHP_DATADIR'           => $string,
            'PHP_SYSCONFDIR'        => $string,
            'PHP_LOCALSTATEDIR'     => $string,
            'PHP_CONFIG_FILE_PATH'  => $string,
            'PHP_CONFIG_FILE_SCAN_DIR' => $string,
            'PHP_SHLIB_SUFFIX'      => $string,
            //'PHP_FD_SETSIZE'            => $int,  // 7.2.0 TODO: web page documentation is wrong, says string.
            'E_ERROR'               => $int,
            'E_WARNING'             => $int,
            'E_PARSE'               => $int,
            'E_NOTICE'              => $int,
            'E_CORE_ERROR'          => $int,
            'E_CORE_WARNING'        => $int,
            'E_COMPILE_ERROR'       => $int,
            'E_COMPILE_WARNING'     => $int,
            'E_USER_ERROR'          => $int,
            'E_USER_WARNING'        => $int,
            'E_USER_NOTICE'         => $int,
            'E_DEPRECATED'          => $int,
            'E_USER_DEPRECATED'     => $int,
            'E_ALL'                 => $int,
            'E_STRICT'              => $int,
            '__COMPILER_HALT_OFFSET__' => $int,
            '__LINE__'              => $int,
            'TRUE'                  => $true,
            'FALSE'                 => $false,
            'NULL'                  => $null,
        ];
    }

    /**
     * @param mixed $object
     * @return Type
     * Get a type for the given object. Equivalent to Type::fromObject($object)->asNonLiteralType()
     */
    public static function nonLiteralFromObject($object) : Type
    {
        static $type_map = null;
        if ($type_map === null) {
            $type_map = [
                'integer' => IntType::instance(false),
                'boolean' => BoolType::instance(false),
                'double'  => FloatType::instance(false),
                'string'  => StringType::instance(false),
                'object'  => ObjectType::instance(false),
                'NULL'    => NullType::instance(false),
                'array'   => ArrayType::instance(false),
                'resource' => ResourceType::instance(false),  // For inferring the type of constants STDIN, etc.
            ];
        }
        // gettype(2) doesn't return 'int', it returns 'integer', so use FROM_PHPDOC
        return $type_map[\gettype($object)];
    }

    /**
     * @param mixed $object
     * @return NativeType
     * Get a type for the given object
     * @throws AssertionError if the type was unexpected
     */
    public static function fromObject($object) : NativeType
    {
        switch (\gettype($object)) {
            case 'integer':
                '@phan-var int $object';
                return LiteralIntType::instanceForValue($object, false);
            case 'string':
                '@phan-var string $object';
                return LiteralStringType::instanceForValue($object, false);
            case 'NULL':
                return NullType::instance(false);
            case 'double':
                return FloatType::instance(false);
            case 'object':
                return ObjectType::instance(false);
            case 'boolean':
                return $object ? TrueType::instance(false) : FalseType::instance(false);
            case 'array':
                return ArrayType::instance(false);
            case 'resource':
                return ResourceType::instance(false);  // For inferring the type of constants STDIN, etc.
            default:
                throw new \AssertionError("Unknown type " . gettype($object));
        }
    }

    /**
     * @param string $type_name
     * The name of the internal type such as 'int'
     *
     * @param bool $is_nullable
     * Set to true if the type should be nullable, else pass
     * false
     *
     * @param int $source Type::FROM_*
     *
     * @return Type
     * Get a type for the given type name
     *
     * @throws AssertionError if the type was unexpected
     */
    public static function fromInternalTypeName(
        string $type_name,
        bool $is_nullable,
        int $source
    ) : Type {

        // If this is a generic type (like int[]), return
        // a generic of internal types.
        //
        // When there's a nullability operator such as in
        // `?int[]`, it applies to the array rather than
        // the int
        if (false !== ($pos = \strrpos($type_name, '[]'))) {
            return GenericArrayType::fromElementType(
                self::fromInternalTypeName(
                    \substr($type_name, 0, $pos),
                    false,
                    $source
                ),
                $is_nullable,
                GenericArrayType::KEY_MIXED
            );
        }

        $type_name =
            self::canonicalNameFromName($type_name);

        // TODO: Is this worth optimizing into a lookup table?
        switch (strtolower($type_name)) {
            case 'array':
                return ArrayType::instance($is_nullable);
            case 'bool':
                return BoolType::instance($is_nullable);
            case 'callable':
                return CallableType::instance($is_nullable);
            case 'closure':
                return ClosureType::instance($is_nullable);
            case 'false':
                return FalseType::instance($is_nullable);
            case 'float':
                return FloatType::instance($is_nullable);
            case 'int':
                return IntType::instance($is_nullable);
            case 'mixed':
                return MixedType::instance($is_nullable);
            case 'null':
                return NullType::instance($is_nullable);
            case 'object':
                return ObjectType::instance($is_nullable);
            case 'resource':
                return ResourceType::instance($is_nullable);
            case 'scalar':
                return ScalarRawType::instance($is_nullable);
            case 'string':
                return StringType::instance($is_nullable);
            case 'true':
                return TrueType::instance($is_nullable);
            case 'void':
                return VoidType::instance(false);
            case 'iterable':
                return IterableType::instance($is_nullable);
            case 'static':
                return StaticType::instance($is_nullable);
            case '$this':
                return StaticType::instance($is_nullable);
        }

        if (\substr($type_name, 0, 1) === '?') {
            // @phan-suppress-next-line PhanPossiblyFalseTypeArgument
            return self::fromInternalTypeName(\substr($type_name, 1), true, $source);
        }
        throw new AssertionError("No internal type with name $type_name");
    }

    /**
     * @param string $namespace
     * A fully qualified namespace
     *
     * @param string $type_name
     * The name of the type
     *
     * @return Type
     * A type representing the given namespace and type
     * name.
     *
     * @param bool $is_nullable
     * True if this type can be null, false if it cannot
     * be null.
     */
    public static function fromNamespaceAndName(
        string $namespace,
        string $type_name,
        bool $is_nullable
    ) : Type {
        return self::make($namespace, $type_name, [], $is_nullable, Type::FROM_NODE);
    }

    /**
     * Creates a type for the ReflectionType of a parameter, return value, etc.
     */
    public static function fromReflectionType(
        \ReflectionType $reflection_type
    ) : Type {

        return self::fromStringInContext(
            $reflection_type->__toString(),
            new Context(),
            Type::FROM_NODE
        );
    }

    /**
     * @param string $fully_qualified_string
     * A fully qualified type name
     *
     *
     * @return Type
     * The type with that fully qualified type name (cached for efficiency)
     *
     * @throws InvalidArgumentException if type name was invalid
     *
     * @throws FQSENException
     */
    public static function fromFullyQualifiedString(
        string $fully_qualified_string
    ) : Type {
        static $type_cache = [];
        return $type_cache[$fully_qualified_string] ?? ($type_cache[$fully_qualified_string] = self::fromFullyQualifiedStringInner($fully_qualified_string));
    }

    /**
     * Extracts the parts of this Type from the passed in fully qualified type name.
     * Callers should ensure that the type regex accepts $fully_qualified_string
     *
     * @throws InvalidArgumentException if namespace is missing from something that should have a namespace
     * @suppress PhanPossiblyFalseTypeArgument, PhanPossiblyFalseTypeArgumentInternal
     *
     * @throws FQSENException
     */
    protected static function fromFullyQualifiedStringInner(
        string $fully_qualified_string
    ) : Type {
        if ($fully_qualified_string === '') {
            throw new InvalidArgumentException("Type cannot be empty");
        }
        while (\substr($fully_qualified_string, -1) === ')') {
            if ($fully_qualified_string[0] === '?') {
                $fully_qualified_string = '?' . \substr($fully_qualified_string, 2, -1);
            } else {
                $fully_qualified_string = \substr($fully_qualified_string, 1, -1);
            }
        }
        if (\substr($fully_qualified_string, -2) === '[]') {
            if ($fully_qualified_string[0] === '?') {
                $is_nullable = true;
                $fully_qualified_substring = \substr($fully_qualified_string, 1, -2);
            } else {
                $is_nullable = false;
                $fully_qualified_substring = \substr($fully_qualified_string, 0, -2);
            }
            return GenericArrayType::fromElementType(
                // @phan-suppress-next-line PhanThrowTypeMismatchForCall
                Type::fromFullyQualifiedString($fully_qualified_substring),
                $is_nullable,
                GenericArrayType::KEY_MIXED
            );
        }

        $tuple = self::typeStringComponents($fully_qualified_string);

        $namespace = $tuple->_0;
        $type_name = $tuple->_1;
        $template_parameter_type_name_list = $tuple->_2;
        $is_nullable = $tuple->_3;
        $shape_components = $tuple->_4;
        if (\preg_match('/^(' . self::noncapturing_literal_regex . ')$/', $type_name)) {
            return self::fromEscapedLiteralScalar($type_name);
        }
        if (\is_array($shape_components)) {
            if (\strcasecmp($type_name, 'array') === 0) {
                return ArrayShapeType::fromFieldTypes(
                    self::shapeComponentStringsToTypes($shape_components, new Context(), Type::FROM_NODE),
                    $is_nullable
                );
            }
            if ($type_name === 'Closure' || $type_name === 'callable') {
                return self::fromFullyQualifiedFunctionLike($type_name === 'Closure', $shape_components, $is_nullable);
            }
        }

        if (!$namespace) {
            if (count($template_parameter_type_name_list) > 0) {
                if (\strcasecmp($type_name, 'array') === 0) {
                    // template parameter type list
                    $template_parameter_type_list = self::createTemplateParameterTypeList($template_parameter_type_name_list);
                    return self::parseGenericArrayTypeFromTemplateParameterList($template_parameter_type_list, $is_nullable);
                } elseif (\strcasecmp($type_name, 'iterable') === 0) {
                    // template parameter type list
                    $template_parameter_type_list = self::createTemplateParameterTypeList($template_parameter_type_name_list);
                    return self::parseGenericIterableTypeFromTemplateParameterList($template_parameter_type_list, $is_nullable);
                }
            }
            return self::fromInternalTypeName(
                $fully_qualified_string,
                $is_nullable,
                Type::FROM_NODE
            );
        }

        // Map the names of the types to actual types in the
        // template parameter type list
        $template_parameter_type_list = self::createTemplateParameterTypeList($template_parameter_type_name_list);

        if (0 !== \strpos($namespace, '\\')) {
            $namespace = '\\' . $namespace;
        }

        if ($type_name === '') {
            throw new EmptyFQSENException("Type was not fully qualified", $fully_qualified_string);
        }
        if ($namespace === '') {
            throw new InvalidFQSENException("Type was not fully qualified", $fully_qualified_string);
        }

        return self::make(
            $namespace,
            $type_name,
            $template_parameter_type_list,
            $is_nullable,
            Type::FROM_NODE
        );
    }

    private static function fromEscapedLiteralScalar(string $escaped_literal) : ScalarType
    {
        $is_nullable = $escaped_literal[0] === '?';
        if ($is_nullable) {
            $escaped_literal = \substr($escaped_literal, 1);
        }
        if ($escaped_literal[0] === "'") {
            // @phan-suppress-next-line PhanPossiblyFalseTypeArgument
            return LiteralStringType::fromEscapedString($escaped_literal, $is_nullable);
        }
        $value = filter_var($escaped_literal, FILTER_VALIDATE_INT);
        if (\is_int($value)) {
            return LiteralIntType::instanceForValue($value, $is_nullable);
        }
        return FloatType::instance($is_nullable);
    }

    /**
     * @param array<int,string> $template_parameter_type_name_list
     * @return array<int,UnionType>
     */
    private static function createTemplateParameterTypeList(array $template_parameter_type_name_list)
    {
        return \array_map(function (string $type_name) : UnionType {
            return UnionType::fromFullyQualifiedString($type_name);
        }, $template_parameter_type_name_list);
    }

    /**
     * @param bool $is_closure_type
     * @param array<int,string> $shape_components
     * @param bool $is_nullable
     * @throws AssertionError if creating a closure/callable from the arguments failed
     * @suppress PhanPossiblyFalseTypeArgument, PhanPossiblyFalseTypeArgumentInternal
     */
    private static function fromFullyQualifiedFunctionLike(
        bool $is_closure_type,
        array $shape_components,
        bool $is_nullable
    ) : FunctionLikeDeclarationType {
        if (count($shape_components) === 0) {
            // The literal int '0' is a valid union type, but it's falsey, so check the count instead.
            // shouldn't happen
            throw new AssertionError("Expected at least one component of a closure phpdoc type");
        }
        $return_type = \array_pop($shape_components);
        if ($return_type[0] === '(' && \substr($return_type, -1) === ')') {
            // TODO: Maybe catch that in UnionType parsing instead
            $return_type = \substr($return_type, 1, -1);
        }
        $params = self::closureParamComponentStringsToParams($shape_components, new Context(), Type::FROM_NODE);
        $return_type = UnionType::fromStringInContext($return_type, new Context(), Type::FROM_NODE);

        if ($is_closure_type) {
            return new ClosureDeclarationType(new Context(), $params, $return_type, false, $is_nullable);
        } else {
            return new CallableDeclarationType(new Context(), $params, $return_type, false, $is_nullable);
        }
    }
    /**
     * @param array<int,UnionType> $template_parameter_type_list
     * @param bool $is_nullable
     */
    private static function parseGenericArrayTypeFromTemplateParameterList(
        array $template_parameter_type_list,
        bool $is_nullable
    ) : ArrayType {
        $template_count = count($template_parameter_type_list);
        if ($template_count <= 2) {  // array<T> or array<key, T>
            $key_type = ($template_count === 2)
                ? GenericArrayType::keyTypeFromUnionTypeValues($template_parameter_type_list[0])
                : GenericArrayType::KEY_MIXED;

            $types = $template_parameter_type_list[$template_count - 1]->getTypeSet();
            if (count($types) === 1) {
                return GenericArrayType::fromElementType(
                    // @phan-suppress-next-line PhanPossiblyFalseTypeArgument
                    \reset($types),
                    $is_nullable,
                    $key_type
                );
            } elseif (count($types) > 1) {
                return new GenericMultiArrayType(
                    $types,
                    $is_nullable,
                    $key_type
                );
            }
        }
        return ArrayType::instance($is_nullable);
    }

    /**
     * @param array<int,UnionType> $template_parameter_type_list
     * @param bool $is_nullable
     */
    private static function parseGenericIterableTypeFromTemplateParameterList(
        array $template_parameter_type_list,
        bool $is_nullable
    ) : Type {
        $template_count = count($template_parameter_type_list);
        if ($template_count <= 2) {  // iterable<T> or iterable<key, T>
            $key_union_type = ($template_count === 2)
                ? $template_parameter_type_list[0]
                : UnionType::empty();
            $value_union_type = $template_parameter_type_list[$template_count - 1];
            return GenericIterableType::fromKeyAndValueTypes($key_union_type, $value_union_type, $is_nullable);
        }
        return IterableType::instance($is_nullable);
    }

    /**
     * @param string $string
     * A string representing a type
     *
     * @param Context $context
     * The context in which the type string was
     * found
     *
     * @param int $source
     * Type::FROM_NODE, Type::FROM_TYPE, or Type::FROM_PHPDOC
     *
     * @param ?CodeBase $code_base
     * May be provided to resolve 'parent' in the context
     * (e.g. if parsing complex phpdoc).
     * Unnecessary in most use cases.
     *
     * @return Type
     * Parse a type from the given string
     *
     * @suppress PhanPossiblyFalseTypeArgument, PhanPossiblyFalseTypeArgumentInternal
     */
    public static function fromStringInContext(
        string $string,
        Context $context,
        int $source,
        CodeBase $code_base = null
    ) : Type {
        if ($string === '') {
            throw new AssertionError("Type cannot be empty");
        }
        while (\substr($string, -1) === ')') {
            if ($string[0] === '?') {
                if ($string[1] !== '(') {
                    // Account for the Closure(params...):return syntax
                    break;
                }
                $string = '?' . \substr($string, 2, -1);
            } else {
                if ($string[0] !== '(') {
                    break;
                }
                $string = \substr($string, 1, -1);
            }
        }

        if (\substr($string, -2) === '[]') {
            if ($string[0] === '?') {
                $is_nullable = true;
                $substring = \substr($string, 1, -2);
            } else {
                $is_nullable = false;
                $substring = \substr($string, 0, -2);
            }
            if ($substring === '') {
                return ArrayType::instance($is_nullable);
            }
            $types = UnionType::fromStringInContext(
                $substring,
                $context,
                $source,
                $code_base
            );

            $type_set = $types->getTypeSet();
            if (count($type_set) === 1) {
                return GenericArrayType::fromElementType(
                    \reset($type_set),
                    $is_nullable,
                    GenericArrayType::KEY_MIXED
                );
            } else {
                return new GenericMultiArrayType(
                    $type_set,
                    $is_nullable,
                    GenericArrayType::KEY_MIXED
                );
            }
        }
        // If our scope has a generic type identifier defined on it
        // that matches the type string, return that type.
        if ($source === Type::FROM_PHPDOC && $context->getScope()->hasTemplateType($string)) {
            return $context->getScope()->getTemplateType($string);
        }

        // Extract the namespace, type and parameter type name list
        $tuple = self::typeStringComponents($string);

        $namespace = $tuple->_0;
        $type_name = $tuple->_1;
        $template_parameter_type_name_list = $tuple->_2;
        $is_nullable = $tuple->_3;
        $shape_components = $tuple->_4;

        if (\preg_match('/^(' . self::noncapturing_literal_regex . ')$/', $type_name)) {
            return self::fromEscapedLiteralScalar($type_name);
        }

        if (\is_array($shape_components)) {
            if (\strcasecmp($type_name, 'array') === 0) {
                return ArrayShapeType::fromFieldTypes(
                    self::shapeComponentStringsToTypes($shape_components, $context, $source, $code_base),
                    $is_nullable
                );
            }
            if ($type_name === 'Closure' || $type_name === 'callable') {
                if ($type_name === 'Closure' && $code_base !== null) {
                    self::checkClosureString($code_base, $context, $string);
                }
                return self::fromFunctionLikeInContext($type_name === 'Closure', $shape_components, $context, $source, $is_nullable);
            }
        }

        // Map the names of the types to actual types in the
        // template parameter type list
        $template_parameter_type_list =
            \array_map(function (string $type_name) use ($code_base, $context, $source) : UnionType {
                return UnionType::fromStringInContext($type_name, $context, $source, $code_base);
            }, $template_parameter_type_name_list);

        // @var bool
        // True if this type name if of the form 'C[]'
        $is_generic_array_type =
            self::isGenericArrayString($type_name);

        // If this is a generic array type, get the name of
        // the type of each element
        $non_generic_array_type_name = $type_name;
        if ($is_generic_array_type
           && false !== ($pos = \strrpos($type_name, '[]'))
        ) {
            $non_generic_array_type_name =
                \substr($type_name, 0, $pos);
        }

        // Check to see if the type name is mapped via
        // a using clause.
        //
        // Gotta check this before checking for native types
        // because there are monsters out there that will
        // remap the names via things like `use \Foo\String`.
        $non_generic_partially_qualified_array_type_name =
            $non_generic_array_type_name;
        if ($namespace) {
            $non_generic_partially_qualified_array_type_name =
                $namespace . '\\' . $non_generic_partially_qualified_array_type_name;
        }

        if ($is_generic_array_type && false !== \strrpos($non_generic_array_type_name, '[]')) {
            return GenericArrayType::fromElementType(
                Type::fromStringInContext($non_generic_partially_qualified_array_type_name, $context, $source),
                $is_nullable,
                GenericArrayType::KEY_MIXED
            );
        }
        if (\substr($non_generic_partially_qualified_array_type_name, 0, 1) !== '\\' && $context->hasNamespaceMapFor(
            \ast\flags\USE_NORMAL,
            $non_generic_partially_qualified_array_type_name
        )) {
            $fqsen =
                $context->getNamespaceMapFor(
                    \ast\flags\USE_NORMAL,
                    $non_generic_partially_qualified_array_type_name
                );

            if ($is_generic_array_type) {
                return GenericArrayType::fromElementType(
                    Type::make(
                        $fqsen->getNamespace(),
                        $fqsen->getName(),
                        $template_parameter_type_list,
                        false,
                        $source
                    ),
                    $is_nullable,
                    GenericArrayType::KEY_MIXED
                );
            }

            return Type::make(
                $fqsen->getNamespace(),
                $fqsen->getName(),
                $template_parameter_type_list,
                $is_nullable,
                $source
            );
        }

        // If this was a fully qualified type, we're all
        // set
        if ($namespace && $namespace[0] === '\\') {
            return self::make(
                $namespace,
                $type_name,
                $template_parameter_type_list,
                $is_nullable,
                $source
            );
        }

        if (self::isInternalTypeString($type_name, $source)) {
            if (count($template_parameter_type_list) > 0) {
                if (strtolower($type_name) === 'array') {
                    return self::parseGenericArrayTypeFromTemplateParameterList($template_parameter_type_list, $is_nullable);
                }
                if (strtolower($type_name) === 'iterable') {
                    return self::parseGenericIterableTypeFromTemplateParameterList($template_parameter_type_list, $is_nullable);
                }
                // TODO: Warn about unrecognized types.
            }
            return self::fromInternalTypeName($type_name, $is_nullable, $source);
        }

        // Things like `self[]` or `$this[]`
        if ($is_generic_array_type
            && self::isSelfTypeString($non_generic_array_type_name)
            && $context->isInClassScope()
        ) {
            // The element type can be nullable.
            // Independently, the array of elements can also be nullable.
            if (stripos($non_generic_array_type_name, 'parent') !== false) {
                // Will throw if $code_base is null or there is no parent type
                $element_type = self::maybeFindParentType($non_generic_array_type_name[0] === '?', $context, $code_base);
            } else {
                // Equivalent to getClassFQSEN()->asType() but slightly faster (this is frequently used)
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall
                $element_type = self::fromFullyQualifiedString(
                    $context->getClassFQSEN()->__toString()
                );
            }

            return GenericArrayType::fromElementType(
                $element_type,
                $is_nullable,
                GenericArrayType::KEY_MIXED
            );
        }

        // If this is a type referencing the current class
        // in scope such as 'self' or 'static', return that.
        if (self::isSelfTypeString($type_name)
            && $context->isInClassScope()
        ) {
            if (stripos($type_name, 'parent') !== false) {
                // Will throw if $code_base is null or there is no parent type
                return self::maybeFindParentType($is_nullable, $context, $code_base);
            }
            if ($source === self::FROM_PHPDOC && $context->getScope()->isInTraitScope()) {
                return SelfType::instance($is_nullable);
            }
            // Equivalent to getClassFQSEN()->asType()->withIsNullable but slightly faster (this is frequently used)
            // @phan-suppress-next-line PhanThrowTypeAbsentForCall
            return self::fromFullyQualifiedString(
                $context->getClassFQSEN()->__toString()
            )->withIsNullable($is_nullable);
        }

        // Merge the current namespace with the given relative
        // namespace
        $context_namespace = $context->getNamespace();
        if ($context_namespace) {
            if ($namespace) {
                $namespace = \rtrim($context_namespace, '\\') . '\\' . $namespace;
            } else {
                $namespace = $context_namespace;
            }
        } else {
            $namespace = '\\' . $namespace;
        }

        // Attach the context's namespace to the type name
        return self::make(
            $namespace,
            $type_name,
            $template_parameter_type_list,
            $is_nullable,
            $source
        );
    }


    private static function checkClosureString(
        CodeBase $code_base,
        Context $context,
        string $string
    ) {
        // Note: Because of the regex, the namespace should be either empty or '\\'
        if (preg_match('/^\??\\\\/', $string) > 0) {
            // This is fully qualified
            return;
        }
        // This check is probably redundant, we can't parse
        if ($context->hasNamespaceMapFor(
            \ast\flags\USE_NORMAL,
            'Closure'
        )) {
            $fqsen = $context->getNamespaceMapFor(
                \ast\flags\USE_NORMAL,
                'Closure'
            );
            $namespace = $fqsen->getNamespace();
        } else {
            $namespace = $context->getNamespace();
        }
        if (($namespace ?: '\\') !== '\\') {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::CommentAmbiguousClosure,
                $context->getLineNumberStart(),
                $string,
                $namespace . '\\Closure'
            );
        }
    }
    /**
     * @throws IssueException (TODO: Catch, emit, and proceed?
     */
    private static function maybeFindParentType(bool $is_nullable, Context $context, CodeBase $code_base = null) : Type
    {
        if ($code_base === null) {
            return MixedType::instance($is_nullable);
        }
        $parent_type = UnionTypeVisitor::findParentType($context, $code_base);
        if (!$parent_type) {
            return MixedType::instance($is_nullable);
        }

        return $parent_type->withIsNullable($is_nullable);
    }

    /**
     * @param bool $is_closure_type
     * @param array<int,string> $shape_components
     * @param Context $context
     * @param int $source
     * @param bool $is_nullable
     * @throws AssertionError if the components were somehow invalid
     * @suppress PhanPossiblyFalseTypeArgument
     */
    private static function fromFunctionLikeInContext(
        bool $is_closure_type,
        array $shape_components,
        Context $context,
        int $source,
        bool $is_nullable
    ) : FunctionLikeDeclarationType {
        $return_type = \array_pop($shape_components);
        if (!$return_type) {
            throw new AssertionError("Expected a return type");
        }
        if ($return_type[0] === '(' && \substr($return_type, -1) === ')') {
            $return_type = \substr($return_type, 1, -1);
        }
        $params = self::closureParamComponentStringsToParams($shape_components, $context, $source);
        $return = UnionType::fromStringInContext($return_type, $context, $source);
        if ($is_closure_type) {
            return new ClosureDeclarationType($context, $params, $return, false, $is_nullable);
        } else {
            return new CallableDeclarationType($context, $params, $return, false, $is_nullable);
        }
    }

    /**
     * @param array<string|int,string> $shape_components Maps field keys (integers or strings) to the corresponding type representations
     * @param Context $context
     * @param int $source
     * @param ?CodeBase $code_base for resolving 'parent'
     * @return array<string|int,UnionType> The types for the representations of types, in the given $context
     */
    private static function shapeComponentStringsToTypes(array $shape_components, Context $context, int $source, CodeBase $code_base = null) : array
    {
        $result = [];
        foreach ($shape_components as $key => $component_string) {
            if (\is_string($key) && \substr($key, -1) === '?') {
                if (\substr($component_string, -1) === '=') {
                    $component_string = \substr($component_string, 0, -1);
                }
                $key = \substr($key, 0, -1);
                $result[$key] = UnionType::fromStringInContext($component_string, $context, $source, $code_base)->withIsPossiblyUndefined(true);
            } elseif (\substr($component_string, -1) === '=') {
                $component_string = \substr($component_string, 0, -1);
                $result[$key] = UnionType::fromStringInContext($component_string, $context, $source, $code_base)->withIsPossiblyUndefined(true);
            } else {
                $result[$key] = UnionType::fromStringInContext($component_string, $context, $source, $code_base);
            }
        }
        return $result;
    }

    /**
     * @param array<int,string> $param_components Maps field keys (integers or strings) to the corresponding type representations
     * @param Context $context
     * @param int $source
     * @return array<int,ClosureDeclarationParameter> The types for the representations of types, in the given $context
     *
     * @see Comment::magicParamFromMagicMethodParamString() - This is similar but has minor differences, such as references
     */
    private static function closureParamComponentStringsToParams(array $param_components, Context $context, int $source) : array
    {
        $result = [];
        foreach ($param_components as $param_string) {
            if ($param_string === '') {
                // TODO: warn
                continue;
            }
            if (preg_match('/^(' . UnionType::union_type_regex . ')?\s*(&\s*)?(?:(\.\.\.)\s*)?(?:\$' . Comment::WORD_REGEX . ')?((?:\s*=.*)?)$/', $param_string, $param_match)) {
                // Note: a closure declaration can be by reference, unlike (at)method
                $union_type_string = $param_match[1] ?: 'mixed';
                $union_type = UnionType::fromStringInContext(
                    $union_type_string,
                    $context,
                    $source
                );
                $is_reference = $param_match[19] !== '';
                $is_variadic = $param_match[20] === '...';
                $default_str = $param_match[22];
                $has_default_value = $default_str !== '';
                if ($has_default_value) {
                    $default_value_repr = trim(explode('=', $default_str, 2)[1]);
                    if (strcasecmp($default_value_repr, 'null') === 0) {
                        $union_type = $union_type->nullableClone();
                    }
                }
                // $var_name = $param_match[19]; // would be unused
                $result[] = new ClosureDeclarationParameter($union_type, $is_variadic, $is_reference, $has_default_value);
            }  // TODO: Otherwise, warn
        }
        return $result;
    }


    /**
     * @var ?UnionType of [$this]
     */
    protected $singleton_union_type;

    /**
     * @return UnionType
     * A UnionType representing this and only this type
     */
    public function asUnionType() : UnionType
    {
        // return new UnionType([$this]);
        // Memoize the set of types. The constructed UnionType object can be modified later, so it isn't memoized.
        return $this->singleton_union_type ?? ($this->singleton_union_type = new UnionType([$this], true));
    }

    /**
     * @return FQSEN
     * A fully-qualified structural element name derived
     * from this type
     *
     * @see FullyQualifiedClassName::fromType() for a method that always returns FullyQualifiedClassName
     */
    public function asFQSEN() : FQSEN
    {
        // Note: some subclasses, such as CallableType, return different subtypes of FQSEN
        return FullyQualifiedClassName::fromType($this);
    }

    /**
     * @return string
     * The name associated with this type
     */
    public function getName() : string
    {
        return $this->name;
    }

    /**
     * @return string
     * The namespace associated with this type
     */
    public function getNamespace() : string
    {
        return $this->namespace;
    }

    /**
     * Is this nullable?
     *
     * E.g. returns true for `?array`, `null`, etc.
     */
    public function getIsNullable() : bool
    {
        return $this->is_nullable;
    }

    /**
     * Returns true if this has some possibly falsey values
     */
    public function getIsPossiblyFalsey() : bool
    {
        return $this->is_nullable;
    }

    /**
     * Returns true if this is guaranteed to be falsey
     */
    public function getIsAlwaysFalsey() : bool
    {
        return false;  // overridden in FalseType and NullType, as well as literal scalar types
    }

    /**
     * Returns true if this is possibly truthy.
     */
    public function getIsPossiblyTruthy() : bool
    {
        return true;  // overridden in various types. This base class (Type) is implicitly the type of an object, which is always truthy.
    }

    /**
     * Returns true if this is guaranteed to be truthy.
     *
     * Overridden in various types.
     *
     * This base class (Type) is type of an object with a known FQSEN,
     * which is always truthy.
     */
    public function getIsAlwaysTruthy() : bool
    {
        return true;
    }

    /**
     * Returns true for types such as `mixed`, `bool`, `false`
     */
    public function getIsPossiblyFalse() : bool
    {
        return false;
    }

    /**
     * Returns true for non-nullable `FalseType`
     */
    public function getIsAlwaysFalse() : bool
    {
        return false;  // overridden in FalseType
    }

    /**
     * Returns true if this could include the type `true`
     * (e.g. for `mixed`, `bool`, etc.)
     */
    public function getIsPossiblyTrue() : bool
    {
        return false;
    }

    /**
     * Returns true for non-nullable `TrueType`
     */
    public function getIsAlwaysTrue() : bool
    {
        return false;
    }

    /**
     * Returns true for FalseType, TrueType, and BoolType
     */
    public function getIsInBoolFamily() : bool
    {
        return false;
    }

    /**
     * Returns true if this type may satisfy `is_numeric()`
     */
    public function getIsPossiblyNumeric() : bool
    {
        return false;
    }

    /**
     * @param bool $is_nullable
     * Set to true if the type should be nullable, else pass
     * false
     *
     * @return Type
     * A new type that is a copy of this type but with the
     * given nullability value.
     */
    public function withIsNullable(bool $is_nullable) : Type
    {
        if ($is_nullable === $this->is_nullable) {
            return $this;
        }
        return static::make(
            $this->getNamespace(),
            $this->getName(),
            $this->getTemplateParameterTypeList(),
            $is_nullable,
            Type::FROM_TYPE
        );
    }

    /**
     * Returns this type with any falsey types (e.g. false, null, 0, '') removed.
     *
     * Overridden by BoolType, etc.
     * @see self::getIsAlwaysFalsey()
     */
    public function asNonFalseyType() : Type
    {
        // Overridden by BoolType subclass to return TrueType
        return $this->withIsNullable(false);
    }

    /**
     * Returns this type with any truthy types removed.
     *
     * Overridden by BoolType, etc.
     * @see self::getIsAlwaysTruthy()
     */
    public function asNonTruthyType() : Type
    {
        // Overridden by ScalarType, BoolType, etc.
        return NullType::instance(false);
    }

    /**
     * Returns this type with the type `false` removed.
     *
     * Overridden by BoolType, etc.
     * @see self::getIsAlwaysFalse()
     */
    public function asNonFalseType() : Type
    {
        return $this;
    }

    /**
     * Returns this type with the type `true` removed.
     *
     * Overridden by BoolType, etc.
     * @see self::getIsAlwaysTrue()
     */
    public function asNonTrueType() : Type
    {
        return $this;
    }

    /**
     * @return bool
     * True if this is a native type (like int, string, etc.)
     *
     */
    public function isNativeType() : bool
    {
        return false;
    }

    /**
     * @return bool
     * True if this is a native type or an array of native types
     * (like int, string, bool[], etc.),
     */
    private static function isInternalTypeString(string $original_type_name, int $source) : bool
    {
        $type_name = \str_replace('[]', '', strtolower($original_type_name));
        if ($source === Type::FROM_PHPDOC) {
            $type_name = self::canonicalNameFromName($type_name);  // Have to convert boolean[] to bool
        }
        if (!\array_key_exists($type_name, self::_internal_type_set)) {
            return $original_type_name === '$this';  // This is the only case-sensitive check.
        }
        // All values of $type_name exist as a valid phpdoc type, but some don't exist as real types.
        if ($source === Type::FROM_NODE && \array_key_exists($type_name, self::_soft_internal_type_set)) {
            return false;
        }
        return true;
    }

    /**
     * @return bool
     * True if this type is a type referencing the
     * class context in which it exists such as 'static'
     * or 'self'.
     */
    public function isSelfType() : bool
    {
        // TODO: Ensure that this is always a SelfType instance
        return $this->namespace === '\\' && self::isSelfTypeString($this->name);
    }

    /**
     * @return bool
     * True if this type is a type referencing the
     * class context 'static'.
     * Overridden in the subclass StaticType
     */
    public function isStaticType() : bool
    {
        return false;
    }

    /**
     * Returns true if this has any instance of `static` or `self`.
     * This is overridden in subclasses such as `SelfType`.
     */
    public function hasStaticOrSelfTypesRecursive(CodeBase $code_base) : bool
    {
        $union_type = $this->iterableValueUnionType($code_base);
        if (!$union_type) {
            return false;
        }
        foreach ($union_type->getTypeSet() as $type) {
            if ($type->hasStaticOrSelfTypesRecursive($code_base)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $type_string
     * A string defining a type such as 'self' or 'int'.
     *
     * @return bool
     * True if the given type references the class context
     * in which it exists such as 'self' or 'parent'
     */
    public static function isSelfTypeString(
        string $type_string
    ) : bool {
        // Note: While 'self' and 'parent' are case-insensitive, '$this' is case-sensitive
        // Not sure if that should extend to phpdoc.
        return \preg_match('/^\\\\?([sS][eE][lL][fF]|[pP][aA][rR][eE][nN][tT]|\$this)$/', $type_string) > 0;
    }

    /**
     * @param string $type_string
     * A string defining a type such as 'static' or 'int'.
     *
     * @return bool
     * True if the given type references the class context
     * in which it exists is '$this' or 'static'
     */
    public static function isStaticTypeString(
        string $type_string
    ) : bool {
        // Note: While 'self' and 'parent' are case-insensitive, '$this' is case-sensitive
        // Not sure if that should extend to phpdoc.
        return \preg_match('/^\\\\?([sS][tT][aA][tT][iI][cC]|\\$this)$/', $type_string) > 0;
    }

    /**
     * @return bool
     * True if this type is scalar.
     */
    public function isScalar() : bool
    {
        return false;  // Overridden in subclass ScalarType
    }

    /**
     * @return bool
     * True if this type is a printable scalar.
     * @internal
     */
    public function isPrintableScalar() : bool
    {
        return false;  // Overridden in subclass ScalarType
    }

    /**
     * @return bool
     * True if this type is a valid operand for a bitwise operator ('|', '&', or '^').
     * @internal
     */
    public function isValidBitwiseOperand() : bool
    {
        return false;  // Overridden in subclasses
    }

    /**
     * @return bool
     * True if this type is a callable or a Closure.
     */
    public function isCallable() : bool
    {
        return false;  // Overridden in subclass CallableType, ClosureType, FunctionLikeDeclarationType
    }

    /**
     * @return bool
     * True if this type is an object (or the phpdoc `object`)
     */
    public function isObject() : bool
    {
        return true;  // Overridden in various subclasses
    }

    /**
     * @return bool
     * True if this type is an object (and not the phpdoc `object` or a template)
     */
    public function isObjectWithKnownFQSEN() : bool
    {
        return true;  // Overridden in various subclasses
    }

    /**
     * @return bool
     * True if this type is possibly an object (or the phpdoc `object`)
     * This is the same as isObject(), except that it returns true for the exact class of IterableType.
     */
    public function isPossiblyObject() : bool
    {
        return true;  // Overridden in various subclasses
    }

    /**
     * @return bool
     * True if this type is iterable.
     */
    public function isIterable() : bool
    {
        return false;  // Overridden in subclass IterableType (with subclass ArrayType)
    }

    /**
     * @return bool
     * True if this type is array-like (is of type array, is
     * a generic array, or implements ArrayAccess).
     */
    public function isArrayLike() : bool
    {
        // includes both nullable and non-nullable ArrayAccess/array
        // (Overridden by ArrayType)
        return $this->isArrayAccess();
    }

    /**
     * @return bool
     * True if this is a generic type such as 'int[]' or 'string[]'.
     * Currently, this is the same as `$type instanceof GenericArrayInterface`
     * @suppress PhanUnreferencedPublicMethod
     */
    public function isGenericArray() : bool
    {
        return false;  // Overridden in GenericArrayType and ArrayShapeType
    }

    /**
     * @return bool - Returns true if this is `\ArrayAccess` (nullable or not)
     */
    public function isArrayAccess() : bool
    {
        return (\strcasecmp($this->getName(), 'ArrayAccess') === 0
            && $this->getNamespace() === '\\');
    }

    /**
     * Is this an array or ArrayAccess, or a subtype of those?
     * E.g. returns true for `\ArrayObject`, `array<int,string>`, etc.
     */
    public function isArrayOrArrayAccessSubType(CodeBase $code_base) : bool
    {
        return $this->asExpandedTypes($code_base)->hasArrayAccess();
    }

    /**
     * @return bool - Returns true if this is \Traversable (nullable or not)
     */
    public function isTraversable() : bool
    {
        return (\strcasecmp($this->getName(), 'Traversable') === 0
            && $this->getNamespace() === '\\');
    }

    /**
     * @return bool - Returns true if this is \Generator (nullable or not)
     * @suppress PhanUnreferencedPublicMethod
     */
    public function isGenerator() : bool
    {
        return (\strcasecmp($this->getName(), 'Generator') === 0
            && $this->getNamespace() === '\\');
    }

    /**
     * @param string $type_name
     * A non-namespaced type name like 'int[]'
     *
     * @return bool
     * True if this is a generic type such as 'int[]' or
     * 'string[]'.
     */
    private static function isGenericArrayString(string $type_name) : bool
    {
        if (\strrpos($type_name, '[]') !== false) {
            return $type_name !== '[]';
        }
        return false;
    }

    /**
     * @return ?UnionType returns the iterable key's union type, if this is a subtype of iterable. null otherwise.
     */
    public function iterableKeyUnionType(CodeBase $unused_code_base)
    {
        if ($this->namespace === '\\') {
            $name = strtolower($this->name);
            if ($name === 'traversable' || $name === 'iterator') {
                return $this->keyTypeOfTraversable();
            }
            // TODO: Abstract this out for all internal classes
            if ($name === 'generator') {
                return $this->keyTypeOfGenerator();
            }
            // TODO: If this is a subclass of iterator, look up the signature of MyClass->key()
        }

        /*
        if ($this->namespace !== '\\') {
            return null;
        }
        // TODO: check for Traversable and Generator and other subclasses of Traversable
        */
        return null;
    }

    /**
     * @return ?UnionType returns the iterable value's union type if this is a subtype of iterable, null otherwise.
     *
     * This is overridden by the array subclasses
     */
    public function iterableValueUnionType(CodeBase $unused_code_base)
    {
        if ($this->namespace === '\\') {
            $name = strtolower($this->name);
            if ($name === 'traversable' || $name === 'iterator') {
                return $this->valueTypeOfTraversable();
            }
            // TODO: Abstract this out for all internal classes
            if ($name === 'generator') {
                return $this->valueTypeOfGenerator();
            }
            // TODO: If this is a subclass of iterator, look up the signature of MyClass->current()
        }
        return null;
    }

    // TODO: Use a template-based abstraction so that this boilerplate can be removed
    /** @return ?UnionType */
    private function keyTypeOfTraversable()
    {
        $template_type_list = $this->template_parameter_type_list;
        if (count($template_type_list) === 2) {
            return $template_type_list[0];
        }
        return null;
    }

    /** @return ?UnionType */
    private function valueTypeOfTraversable()
    {
        $template_type_list = $this->template_parameter_type_list;
        $count = count($template_type_list);
        if ($count >= 1 && $count <= 2) {
            return $template_type_list[$count - 1];
        }
        return null;
    }


    /** @return ?UnionType */
    private function keyTypeOfGenerator()
    {
        $template_type_list = $this->template_parameter_type_list;
        if (count($template_type_list) >= 2 && count($template_type_list) <= 4) {
            return $template_type_list[0];
        }
        return null;
    }

    /** @return ?UnionType */
    private function valueTypeOfGenerator()
    {
        $template_type_list = $this->template_parameter_type_list;
        if (count($template_type_list) >= 2 && count($template_type_list) <= 4) {
            return $template_type_list[1];
        }
        return null;
    }

    /**
     * @param int $key_type
     * Corresponds to the type of the array keys. Set this to a GenericArrayType::KEY_* constant.
     *
     * @return Type
     * Get a new type which is the generic array version of
     * this type. For instance, 'int' will produce 'int[]'.
     *
     * As a special case to reduce false positives, 'array' (with no known types) will produce 'array'
     *
     * Overridden in subclasses
     */
    public function asGenericArrayType(int $key_type) : Type
    {
        return GenericArrayType::fromElementType($this, false, $key_type);
    }

    /**
     * @return bool
     * True if this type has any template parameter types
     * @suppress PhanUnreferencedPublicMethod potentially used in the future
     *           TODO: Would need to override this in ArrayShapeType, GenericArrayType
     */
    public function hasTemplateParameterTypes() : bool
    {
        return count($this->template_parameter_type_list) > 0;
    }

    /**
     * @return array<int,UnionType>
     * The set of types filling in template parameter types defined
     * on the class specified by this type.
     */
    public function getTemplateParameterTypeList()
    {
        return $this->template_parameter_type_list;
    }

    /**
     * @param CodeBase $code_base
     * The code base to look up classes against
     *
     * @return array<string,UnionType>
     * A map from template type identifier to a concrete type
     */
    public function getTemplateParameterTypeMap(CodeBase $code_base)
    {
        return $this->memoize(__METHOD__, function () use ($code_base) : array {
            $fqsen = FullyQualifiedClassName::fromType($this);

            if (!($fqsen instanceof FullyQualifiedClassName)) {
                return [];
            }

            if (!$code_base->hasClassWithFQSEN($fqsen)) {
                return [];
            }

            $class = $code_base->getClassByFQSEN($fqsen);

            $template_parameter_type_list =
                $this->getTemplateParameterTypeList();

            $map = [];
            foreach (\array_keys($class->getTemplateTypeMap()) as $i => $identifier) {
                if (isset($template_parameter_type_list[$i])) {
                    $map[$identifier] = $template_parameter_type_list[$i];
                }
            }

            return $map;
        });
    }

    /**
     * @param CodeBase $code_base
     * The code base to use in order to find super classes, etc.
     *
     * @param $recursion_depth
     * This thing has a tendency to run-away on me. This tracks
     * how bad I messed up by seeing how far the expanded types
     * go
     *
     * @return UnionType
     * Expands class types to all inherited classes returning
     * a superset of this type.
     */
    public function asExpandedTypes(
        CodeBase $code_base,
        int $recursion_depth = 0
    ) : UnionType {
        // We're going to assume that if the type hierarchy
        // is taller than some value we probably messed up
        // and should bail out.
        if ($recursion_depth >= 20) {
            throw new RecursionDepthException("Recursion has gotten out of hand");
        }
        $union_type = $this->memoize(__METHOD__, /** @return UnionType */ function () use ($code_base, $recursion_depth) {
            $union_type = $this->asUnionType();

            $class_fqsen = $this->asFQSEN();

            if (!($class_fqsen instanceof FullyQualifiedClassName)) {
                return $union_type;
            }

            if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
                return $union_type;
            }

            $clazz = $code_base->getClassByFQSEN($class_fqsen);

            $union_type = $union_type->withUnionType(
                $clazz->getUnionType()
            );
            $additional_union_type = $clazz->getAdditionalTypes();
            if ($additional_union_type !== null) {
                $union_type = $union_type->withUnionType($additional_union_type);
            }

            // Recurse up the tree to include all types
            $representation = $this->__toString();
            $recursive_union_type_builder = new UnionTypeBuilder();
            foreach ($union_type->getTypeSet() as $clazz_type) {
                if ($clazz_type->__toString() !== $representation) {
                    $recursive_union_type_builder->addUnionType(
                        $clazz_type->asExpandedTypes(
                            $code_base,
                            $recursion_depth + 1
                        )
                    );
                } else {
                    $recursive_union_type_builder->addType($clazz_type);
                }
            }
            if (count($this->template_parameter_type_list) > 0) {
                $recursive_union_type_builder->addUnionType(
                    $clazz->resolveParentTemplateType($this->getTemplateParameterTypeMap($code_base))
                );
            }

            // Add in aliases
            // (If enable_class_alias_support is false, this will do nothing)
            $fqsen_aliases = $code_base->getClassAliasesByFQSEN($class_fqsen);
            foreach ($fqsen_aliases as $alias_fqsen_record) {
                $alias_fqsen = $alias_fqsen_record->alias_fqsen;
                $recursive_union_type_builder->addType(
                    $alias_fqsen->asType()
                );
            }

            return $recursive_union_type_builder->getUnionType();
        });
        return $union_type;
    }

    /**
     * @param CodeBase $code_base
     * The code base to use in order to find super classes, etc.
     *
     * @param $recursion_depth
     * This thing has a tendency to run-away on me. This tracks
     * how bad I messed up by seeing how far the expanded types
     * go
     *
     * @return UnionType
     * Expands class types to all inherited classes returning
     * a superset of this type.
     */
    public function asExpandedTypesPreservingTemplate(
        CodeBase $code_base,
        int $recursion_depth = 0
    ) : UnionType {
        // We're going to assume that if the type hierarchy
        // is taller than some value we probably messed up
        // and should bail out.
        if ($recursion_depth >= 20) {
            throw new RecursionDepthException("Recursion has gotten out of hand");
        }
        $union_type = $this->memoize(__METHOD__, /** @return UnionType */ function () use ($code_base, $recursion_depth) {
            $union_type = $this->asUnionType();

            $class_fqsen = $this->asFQSEN();

            if (!($class_fqsen instanceof FullyQualifiedClassName)) {
                return $union_type;
            }

            if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
                return $union_type;
            }

            $clazz = $code_base->getClassByFQSEN($class_fqsen);

            $union_type = $union_type->withUnionType(
                $clazz->getUnionType()
            );

            if (count($this->template_parameter_type_list) > 0) {
                $template_union_type = $clazz->resolveParentTemplateType($this->getTemplateParameterTypeMap($code_base))->asExpandedTypesPreservingTemplate($code_base, $recursion_depth + 1);
                $template_union_type = $template_union_type->withType($this);
            } else {
                $template_union_type = UnionType::empty();
            }

            $additional_union_type = $clazz->getAdditionalTypes();
            if ($additional_union_type !== null) {
                $union_type = $union_type->withUnionType($additional_union_type);
            }

            $representation = $this->__toString();
            $recursive_union_type_builder = new UnionTypeBuilder();
            // Recurse up the tree to include all types
            if (count($this->template_parameter_type_list) > 0) {
                $recursive_union_type_builder->addUnionType(
                    $template_union_type
                );
            }

            foreach ($union_type->getTypeSet() as $clazz_type) {
                if ($clazz_type->__toString() !== $representation) {
                    $recursive_union_type_builder->addUnionType(
                        $clazz_type->asExpandedTypesPreservingTemplate(
                            $code_base,
                            $recursion_depth + 1
                        )
                    );
                } else {
                    $recursive_union_type_builder->addType($clazz_type);
                }
            }

            // Add in aliases
            // (If enable_class_alias_support is false, this will do nothing)
            $fqsen_aliases = $code_base->getClassAliasesByFQSEN($class_fqsen);
            foreach ($fqsen_aliases as $alias_fqsen_record) {
                $alias_fqsen = $alias_fqsen_record->alias_fqsen;
                $recursive_union_type_builder->addType(
                    $alias_fqsen->asType()
                );
            }

            $result = $recursive_union_type_builder->getUnionType();
            if (!$template_union_type->isEmpty()) {
                return $result->replaceWithTemplateTypes($template_union_type);
            }
            return $result;
        });
        return $union_type;
    }

    /**
     * @param CodeBase $code_base
     *
     * @param Type $parent
     *
     * @return bool
     * True if this type represents a class which is a sub-type of
     * the class represented by the passed type.
     */
    public function isSubclassOf(CodeBase $code_base, Type $parent) : bool
    {
        $fqsen = FullyQualifiedClassName::fromType($this);

        $this_clazz = $code_base->getClassByFQSEN(
            $fqsen
        );

        $parent_fqsen = FullyQualifiedClassName::fromType($parent);

        $parent_clazz = $code_base->getClassByFQSEN(
            $parent_fqsen
        );

        return $this_clazz->isSubclassOf($code_base, $parent_clazz);
    }

    /**
     * @param Type[] $target_type_set 1 or more types
     * @return bool
     * True if this Type can be cast to the given Type cleanly.
     * This is overridden by ArrayShapeType to allow array{a:string,b:stdClass} to cast to string[]|stdClass[]
     */
    public function canCastToAnyTypeInSet(array $target_type_set) : bool
    {
        foreach ($target_type_set as $target_type) {
            if ($this->canCastToType($target_type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param Type[] $target_type_set 1 or more types
     * @return bool
     * True if this Type can be cast to the given Type cleanly.
     * This is overridden by ArrayShapeType to allow array{a:string,b:stdClass} to cast to string[]|stdClass[]
     */
    public function canCastToAnyTypeInSetHandlingTemplates(array $target_type_set, CodeBase $code_base) : bool
    {
        foreach ($target_type_set as $target_type) {
            if ($this->canCastToTypeHandlingTemplates($target_type, $code_base)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool
     * True if this Type can be cast to the given Type
     * cleanly
     */
    public function canCastToType(Type $type) : bool
    {
        // Check to see if we have an exact object match
        if ($this === $type) {
            return true;
        }

        if ($type instanceof MixedType) {
            return true;
        }

        // A nullable type cannot cast to a non-nullable type
        if ($this->is_nullable && !$type->is_nullable) {
            // If this is nullable, but that isn't, and we've
            // configured nulls to cast as anything (or as arrays), ignore
            // the nullable part.
            if (Config::get_null_casts_as_any_type()) {
                return $this->withIsNullable(false)->canCastToType($type);
            } elseif (Config::get_null_casts_as_array() && $type->isArrayLike()) {
                return $this->withIsNullable(false)->canCastToType($type);
            }

            return false;
        }

        // Get a non-null version of the type we're comparing
        // against.
        if ($type->is_nullable) {
            $type = $type->withIsNullable(false);

            // Check one more time to see if the types are equal
            if ($this === $type) {
                return true;
            }
        }

        // Test to see if we can cast to the non-nullable version
        // of the target type.
        return $this->canCastToNonNullableType($type);
    }

    /**
     * @return bool
     * True if this Type can be cast to the given Type
     * cleanly (accounting for templates)
     */
    public function canCastToTypeHandlingTemplates(Type $type, CodeBase $code_base) : bool
    {
        // Check to see if we have an exact object match
        if ($this === $type) {
            return true;
        }

        if ($type instanceof MixedType) {
            return true;
        }

        // A nullable type cannot cast to a non-nullable type
        if ($this->is_nullable && !$type->is_nullable) {
            // If this is nullable, but that isn't, and we've
            // configured nulls to cast as anything (or as arrays), ignore
            // the nullable part.
            if (Config::get_null_casts_as_any_type()) {
                return $this->withIsNullable(false)->canCastToType($type);
            } elseif (Config::get_null_casts_as_array() && $type->isArrayLike()) {
                return $this->withIsNullable(false)->canCastToType($type);
            }

            return false;
        }

        // Get a non-null version of the type we're comparing
        // against.
        if ($type->is_nullable) {
            $type = $type->withIsNullable(false);

            // Check one more time to see if the types are equal
            if ($this === $type) {
                return true;
            }
        }

        // Test to see if we can cast to the non-nullable version
        // of the target type.
        return $this->canCastToNonNullableTypeHandlingTemplates($type, $code_base);
    }

    /**
     * @param Type $type
     * A Type which is not nullable. This constraint is not
     * enforced, so be careful.
     *
     * @return bool
     * True if this Type can be cast to the given Type
     * cleanly
     */
    protected function canCastToNonNullableType(Type $type) : bool
    {
        // can't cast native types (includes iterable or array) to object. ObjectType overrides this function.
        if ($type instanceof ObjectType
            && !$this->isNativeType()
        ) {
            return true;
        }

        if (!($type instanceof NativeType)) {
            return false;
        }

        if ($type instanceof MixedType) {
            return true;
        }

        // Check for allowable type conversions from object types to native types
        if ($type::NAME === 'iterable') {
            if ($this->namespace === '\\' && \in_array($this->name, ['Generator', 'Traversable', 'Iterator'], true)) {
                if (count($this->template_parameter_type_list) === 0 || !($type instanceof GenericIterableType)) {
                    return true;
                }
                return $this->canCastTraversableToIterable($type);
            }
        } elseif (\get_class($type) === CallableType::class) {
            return $this->namespace === '\\' && $this->name === 'Closure';
        }
        return false;
    }

    /**
     * @param Type $type
     * A Type which is not nullable. This constraint is not
     * enforced, so be careful.
     *
     * @return bool
     * True if this Type can be cast to the given Type
     * cleanly
     */
    protected function canCastToNonNullableTypeHandlingTemplates(Type $type, CodeBase $code_base) : bool
    {
        if ($this->canCastToNonNullableType($type)) {
            return true;
        }
        if ($this->isObjectWithKnownFQSEN() && $type->isObjectWithKnownFQSEN()) {
            if ($this->name === $type->name && $this->namespace === $type->namespace) {
                return $this->canTemplateTypesCast($type->template_parameter_type_list, $code_base);
            }
        }
        return false;
    }

    private function canTemplateTypesCast(array $other_template_parameter_type_list, CodeBase $code_base) : bool
    {
        foreach ($this->template_parameter_type_list as $i => $param) {
            $other_param = $other_template_parameter_type_list[$i] ?? null;
            if ($other_param !== null) {
                if (!$param->asExpandedTypes($code_base)->canCastToUnionType($other_param)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Precondition: $this represents \Traversable, \Iterator, or \Generator
     */
    private function canCastTraversableToIterable(GenericIterableType $type) : bool
    {
        $template_types = $this->template_parameter_type_list;
        $count = count($template_types);
        $name = $this->name;
        if ($name === 'Traversable' || $name === 'Iterator') {
            // Phan supports Traversable<TValue> and Traversable<TKey, TValue>
            if ($count > 2 || $count < 1) {
                // No idea what this means, assume it passes.
                return true;
            }
            if (!$this->template_parameter_type_list[$count - 1]->canCastToUnionType($type->getElementUnionType())) {
                return false;
            }
            if ($count === 2) {
                if (!$this->template_parameter_type_list[0]->canCastToUnionType($type->getKeyUnionType())) {
                    return false;
                }
            }
            return true;
        } elseif ($name === 'Generator') {
            // Phan partially supports the following syntaxes for PHP doc comments
            // 1. Generator<TValue>
            // 2. Generator<TKey, TValue>
            // 3. Generator<TKey, TValue, TYield>
            // 4. Generator<TKey, TValue, TYield, TReturn> (PHP generators can return a final value, but HHVM cannot)

            // TODO: Handle casting Generator to a Generator with a different number of template parameters
            if ($count > 4 || $count < 1) {
                // No idea what this means, assume it passes
                return true;
            }

            if (!$this->template_parameter_type_list[\min(1, $count - 1)]->canCastToUnionType($type->getElementUnionType())) {
                return false;
            }
            if ($count >= 2) {
                if (!$this->template_parameter_type_list[0]->canCastToUnionType($type->getKeyUnionType())) {
                    return false;
                }
            }
            return true;
        }
        // TODO: Check for template parameters, cast those
        return true;
    }

    /**
     * @param UnionType $union_type
     * A union type to compare against. Resolve it before checking.
     *
     * @param Context $context
     * The context in which this type exists.
     *
     * @param CodeBase $code_base
     * The code base in which both this and the given union
     * types exist.
     *
     * @return bool
     * True if each type within this union type can cast
     * to the given union type.
     *
     * @see StaticType::isExclusivelyNarrowedFormOrEquivalentTo() for how it resolves static.
     * TODO: Refactor.
     *
     * @see UnionType::isExclusivelyNarrowedFormOrEquivalentTo() for a check on union types as a whole.
     */
    public function isExclusivelyNarrowedFormOrEquivalentTo(
        UnionType $union_type,
        Context $context,
        CodeBase $code_base
    ) : bool {

        // Special rule: anything can cast to nothing
        // and nothing can cast to anything
        if ($union_type->isEmpty()) {
            return true;
        }

        // Check to see if the other union type contains this
        if ($union_type->hasType($this)) {
            return true;
        }
        if ($this->getIsNullable() && !$union_type->containsNullable()) {
            return false;
        }
        $this_resolved = $this->withStaticResolvedInContext($context);
        // TODO: Allow casting MyClass<TemplateType> to MyClass (Without the template?


        // TODO: Need to resolve expanded union types (parents, interfaces) of classes *before* this is called.

        // Test to see if this (or any ancestor types) can cast to the given union type.
        $expanded_types = $this_resolved->asExpandedTypes($code_base);
        if ($expanded_types->canCastToUnionType(
            $union_type
        )) {
            return true;
        }
        return false;
    }

    /**
     * @return Type
     * Either this or 'static' resolved in the given context.
     */
    public function withStaticResolvedInContext(
        Context $_
    ) : Type {
        return $this;
    }

    /**
     * @return string
     * A string representation of this type in FQSEN form.
     */
    public function asFQSENString() : string
    {
        $namespace = $this->namespace;
        if (!$namespace) {
            return $this->name;
        }

        if ('\\' === $namespace) {
            return '\\' . $this->name;
        }

        return "{$namespace}\\{$this->name}";
    }

    /**
     * @return string
     * A human readable representation of this type
     * (This is frequently called, so prefer efficient operations)
     */
    public function __toString()
    {
        return $this->memoize(__METHOD__, function () : string {
            $string = $this->asFQSENString();

            if (count($this->template_parameter_type_list) > 0) {
                $string .= $this->templateParameterTypeListAsString();
            }

            if ($this->is_nullable) {
                $string = '?' . $string;
            }

            return $string;
        });
    }

    /**
     * Gets the part of the Type string for the template parameters.
     * Precondition: $this->template_parameter_string is not null.
     */
    private function templateParameterTypeListAsString() : string
    {
        return '<' .
            \implode(',', \array_map(function (UnionType $type) : string {
                return $type->__toString();
            }, $this->template_parameter_type_list)) . '>';
    }

    /**
     * @param string $name
     * Any type name
     *
     * @return string
     * A canonical name for the given type name
     */
    public static function canonicalNameFromName(
        string $name
    ) : string {
        static $map = [
            'boolean'  => 'bool',
            'callback' => 'callable',
            'closure'  => 'Closure',
            'double'   => 'float',
            'integer'  => 'int',
        ];

        return $map[strtolower($name)] ?? $name;
    }

    /**
     * @param string $type_string
     * Any type string such as 'int' or 'Set<int>'
     *
     * @return Tuple5<string,string,array<int,string>,bool,?array<string|int,string>>
     * A 5-tuple with the following types:
     * 0: the namespace
     * 1: the type name.
     * 2: The template parameters, if any
     * 3: Whether or not the type is nullable
     * 4: The shape components, if any. Null unless this is an array shape type string such as 'array{field:int}'
     *
     * NOTE: callers must check for the generic array symbol in the type name or for type names beginning with 'array{' (case-insensitive)
     *
     * NOTE: callers must not mutate the result.
     */
    private static function typeStringComponents(
        string $type_string
    ) {
        // This doesn't depend on any configs; the result can be safely cached.
        static $cache = [];
        return $cache[$type_string] ?? ($cache[$type_string] = self::typeStringComponentsInner($type_string));
    }

    /**
     * @return Tuple5<string,string,array<int,string>,bool,?array<string|int,string>>
     * A 5-tuple with the following types:
     * 0: the namespace
     * 1: the type name.
     * 2: The template parameters, if any
     * 3: Whether or not the type is nullable
     * 4: The shape components, if any. Null unless this is an array shape type string such as 'array{field:int}'
     * @suppress PhanPossiblyFalseTypeArgument
     */
    private static function typeStringComponentsInner(
        string $type_string
    ) {
        // Check to see if we have template parameter types
        $template_parameter_type_name_list = [];
        $shape_components = null;

        $match = [];
        $is_nullable = false;
        if (\preg_match('/^' . self::type_regex_or_this . '$/', $type_string, $match)) {
            $closure_components = $match[3] ?? '';
            if ($closure_components !== '') {
                return self::closureTypeStringComponents($type_string, $closure_components);
            }
            if (!isset($match[2])) {
                // Parse '(X)' as 'X'
                return self::typeStringComponents(\substr($match[1], 1, -1));
            } elseif (!isset($match[4])) {
                if (\substr($type_string, -1) === ')') {
                    // Parse '?(X[]) as '?X[]'
                    return self::typeStringComponents('?' . \substr($match[2], 2, -1));
                } else {
                    return new Tuple5(
                        '',
                        $match[0],
                        [],
                        false,
                        null
                    );
                }
            }
            $type_string = $match[4];

            // Rip out the nullability indicator if it
            // exists and note its nullability
            $is_nullable = ($match[5] ?? '') === '?';
            if ($is_nullable) {
                $type_string = \substr($type_string, 1);
            }

            if (($match[9] ?? '') !== '') {
                $shape_components = self::extractShapeComponents($match[10] ?? '');  // will be empty array for 'array{}'
            } else {
                // Recursively parse this
                $template_parameter_type_name_list = ($match[7] ?? '') !== ''
                    ? self::extractNameList($match[7])
                    : [];
            }
        }

        // Determine if the type name is fully qualified
        // (as specified by a leading backslash).
        // @phan-suppress-next-line PhanPossiblyFalseTypeArgumentInternal
        $is_fully_qualified = (0 === \strpos($type_string, '\\'));

        // @phan-suppress-next-line PhanPossiblyFalseTypeArgumentInternal
        $fq_class_name_elements = \array_filter(\explode('\\', $type_string));

        $class_name =
            (string)\array_pop($fq_class_name_elements);

        $namespace = ($is_fully_qualified ? '\\' : '')
            . \implode('\\', \array_filter(
                $fq_class_name_elements
            ));

        return new Tuple5(
            $namespace,
            $class_name,
            $template_parameter_type_name_list,
            $is_nullable,
            $shape_components
        );
    }

    /**
     * @return Tuple5<string,string,array<int,string>,bool,?array<string|int,string>>
     * A 5-tuple with the following types:
     * 0: the namespace
     * 1: the type name.
     * 2: The template parameters, if any
     * 3: Whether or not the type is nullable
     * 4: The shape components, if any. Null unless this is an array shape type string such as 'array{field:int}'
     */
    private static function closureTypeStringComponents(string $type_string, string $inner) : Tuple5
    {
        // @phan-suppress-next-line PhanPossiblyFalseTypeArgumentInternal
        $parts = self::closureParams(\trim(\substr($inner, 1, -1)));
        // TODO: parse params, same as @method

        // Parse the optional return type for this closure
        $i = \strpos($type_string, $inner) + \strlen($inner);
        $colon_index = \strpos($type_string, ':', $i);

        if ($colon_index !== false) {
            // @phan-suppress-next-line PhanPossiblyFalseTypeArgumentInternal
            $return_type_string = \ltrim(\substr($type_string, $colon_index + 1));
        } else {
            $return_type_string = 'void';
        }
        $parts[] = $return_type_string;

        return new Tuple5(
            '\\',
            preg_match('/^\??callable/i', $type_string) > 0 ? 'callable' : 'Closure',
            [],
            $type_string[0] === '?',
            $parts
        );
    }

    /**
     * @return array<int,string>
     */
    private static function closureParams(string $arg_list) : array
    {
        // Special check if param list has 0 params.
        if ($arg_list === '') {
            return [];
        }
        // TODO: Would need to use a different approach if templates were ever supported
        //       e.g. The magic method parsing doesn't support commas?
        return \array_map('trim', self::extractNameList($arg_list));
    }

    /**
     * @return array<string|int,string> maps field name to field type.
     */
    private static function extractShapeComponents(string $shape_component_string) : array
    {
        $result = [];
        foreach (self::extractNameList($shape_component_string) as $shape_component) {
            // Because these can be nested, there may be more than one ':'. Only consider the first.
            $parts = \explode(':', $shape_component, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $field_name = \trim($parts[0]);
            if ($field_name === '') {
                continue;
            }
            $field_value = \trim($parts[1]);
            $result[$field_name] = $field_value;
        }
        return $result;
    }

    /**
     * Extracts the inner parts of a template name list (i.e. within <>) or a shape component list (i.e. within {})
     * @return array<int,string>
     */
    private static function extractNameList(string $list_string) : array
    {
        $results = [];
        $prev_parts = [];
        $delta = 0;
        foreach (\explode(',', $list_string) as $result) {
            $result = \trim($result);
            $open_bracket_count = \substr_count($result, '<') + \substr_count($result, '{') + \substr_count($result, '(');
            $close_bracket_count = \substr_count($result, '>') + \substr_count($result, '}') + \substr_count($result, ')');
            if (count($prev_parts) > 0) {
                $prev_parts[] = $result;
                $delta += $open_bracket_count - $close_bracket_count;
                if ($delta <= 0) {
                    if ($delta === 0) {
                        $results[] = \implode(',', $prev_parts);
                    }  // ignore unparsable data such as "<T,T2>>" or "T, T2{}}"
                    $prev_parts = [];
                    $delta = 0;
                }
                continue;
            }
            if ($open_bracket_count === 0) {
                $results[] = $result;
                continue;
            }
            $delta = $open_bracket_count - $close_bracket_count;
            if ($delta === 0) {
                $results[] = $result;
            } elseif ($delta > 0) {
                $prev_parts[] = $result;
            }  // otherwise ignore unparsable data such as ">" (should be impossible)

            // e.g. we're breaking up T1<T2<X,Y>> into "T1<T2<X" and "Y>>"
        }
        return $results;
    }

    /**
     * Helper function for internal use by UnionType.
     * Overridden by subclasses.
     */
    public function getNormalizationFlags() : int
    {
        return $this->is_nullable ? self::_bit_nullable : 0;
    }

    /**
     * Returns true if this contains any array shape type instances
     * or literal type instances that could be normalized to
     * regular generic array types or scalar types.
     */
    public function hasArrayShapeOrLiteralTypeInstances() : bool
    {
        return false;
    }

    /**
     * Returns true if this contains any array shape type instances
     * that could be normalized to regular generic array types.
     */
    public function hasArrayShapeTypeInstances() : bool
    {
        return false;
    }

    /**
     * Used to check if this type can be replaced by more specific types, for non-quick mode
     *
     * @internal
     */
    public function shouldBeReplacedBySpecificTypes() : bool
    {
        // Could check for final classes such as stdClass here, but not much of a reason to.
        return true;
    }

    /**
     * Converts this type to one where array shapes are flattened to generic arrays, and literal scalars are converted to the general type for that scalar.
     *
     * E.g. converts the type `array{0:2}` to `array<int,int>`
     *
     * This is overridden by subclasses.
     *
     * @return Type[]
     */
    public function withFlattenedArrayShapeOrLiteralTypeInstances() : array
    {
        return [$this];
    }

    /**
     * Overridden in subclasses such as LiteralIntType
     */
    public function asNonLiteralType() : Type
    {
        return $this;
    }

    /**
     * Returns true if this is a potentially valid operand for a numeric operator.
     * Callers should also check if this is nullable.
     */
    public function isValidNumericOperand() : bool
    {
        return false;
    }

    /**
     * Returns true if this contains a type that is definitely nullable or a non-object.
     * e.g. returns true for false, array, int
     *      returns false for callable, object, iterable, T, etc.
     */
    public function isDefiniteNonObjectType() : bool
    {
        return false;
    }

    /**
     * Returns true if this contains a type that is definitely non-callable
     * e.g. returns true for false, array, int
     *      returns false for callable, array, object, iterable, T, etc.
     */
    public function isDefiniteNonCallableType() : bool
    {
        // Any non-final class could be extended with a callable type.
        // TODO: Check if final
        return false;
    }

    /**
     * Check if this type can satisfy a comparison (<, <=, >, >=)
     * @param int|string|float|bool|null $scalar
     * @param int $flags (e.g. \ast\flags\BINARY_IS_SMALLER)
     * @internal
     * @suppress PhanUnusedPublicMethodParameter
     */
    public function canSatisfyComparison($scalar, int $flags) : bool
    {
        return true;
    }

    /**
     * Perform the binary operation corresponding to $flags on $a OP $b
     * @param array|int|string|float|bool|null $a
     * @param int|string|float|bool|null $b
     * @param int $flags
     * @internal
     */
    public static function performComparison($a, $b, int $flags) : bool
    {
        switch ($flags) {
            case flags\BINARY_IS_GREATER:
                return $a > $b;
            case flags\BINARY_IS_GREATER_OR_EQUAL:
                return $a >= $b;
            case flags\BINARY_IS_SMALLER:
                return $a < $b;
            case flags\BINARY_IS_SMALLER_OR_EQUAL:
                return $a <= $b;
        }
        throw new AssertionError("Impossible flag $flags");
    }

    /**
     * Returns the type after an expression such as `++$x`
     */
    public function getTypeAfterIncOrDec() : UnionType
    {
        if ($this->is_nullable) {
            // ++null is 1
            return UnionType::of([$this->withIsNullable(false), IntType::instance(false)]);
        }
        // ++$obj; doesn't change the object.
        return $this->asUnionType();
    }

    /**
     * Returns the Type for \Traversable
     *
     * @suppress PhanThrowTypeAbsentForCall
     */
    public static function traversableInstance() : Type
    {
        static $instance = null;
        return $instance ?? ($instance = Type::fromFullyQualifiedString('\Traversable'));
    }

    /**
     * Returns the Type for \Throwable
     *
     * @suppress PhanThrowTypeAbsentForCall
     */
    public static function throwableInstance() : Type
    {
        static $instance = null;
        return $instance ?? ($instance = Type::fromFullyQualifiedString('\Throwable'));
    }

    /**
     * Replace the resolved reference to class T (possibly namespaced) with a regular template type.
     *
     * @param array<string,TemplateType> $template_fix_map maps the incorrectly resolved name to the template type
     * @return Type
     *
     * @see UnionType::withTemplateParameterTypeMap() for the opposite
     */
    public function withConvertTypesToTemplateTypes(array $template_fix_map) : Type
    {
        return $template_fix_map[$this->__toString()] ?? $this;
    }

    /**
     * Returns true if this is `MyNs\MyClass<T..>` when $type is `MyNs\MyClass`
     */
    public function isTemplateSubtypeOf(Type $type) : bool
    {
        if ($this->name !== $type->name || $this->namespace !== $type->namespace) {
            return false;
        }
        return \count($this->template_parameter_type_list) > 0;
    }

    /**
     * Returns true for `T` and `T[]` and `\MyClass<T>`, but not `\MyClass<\OtherClass>`
     *
     * Overridden in subclasses.
     */
    public function hasTemplateTypeRecursive() : bool
    {
        foreach ($this->template_parameter_type_list as $type) {
            if ($type->hasTemplateTypeRecursive()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,UnionType> $template_parameter_type_map
     * A map from template type identifiers to concrete types
     *
     * @return UnionType
     * This UnionType with any template types contained herein
     * mapped to concrete types defined in the given map.
     *
     * Overridden in subclasses
     *
     * @see self::withConvertTypesToTemplateTypes() for the opposite
     */
    public function withTemplateParameterTypeMap(
        array $template_parameter_type_map
    ) : UnionType {
        if (!$this->template_parameter_type_list) {
            return $this->asUnionType();
        }
        $new_type_list = [];
        foreach ($this->template_parameter_type_list as $i => $type) {
            $new_type_list[$i] = $type->withTemplateParameterTypeMap($template_parameter_type_map);
        }
        if ($new_type_list === $this->template_parameter_type_list) {
            return $this->asUnionType();
        }
        return self::fromType($this, $new_type_list)->asUnionType();
    }

    /**
     * Precondition: Callers should check isObjectWithKnownFQSEN
     */
    public function hasSameNamespaceAndName(Type $type) : bool
    {
        return $this->name === $type->name && $this->namespace === $type->namespace;
    }
}
