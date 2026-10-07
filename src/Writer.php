<?php

namespace Nogrod\XMLClientRuntime;

/**
 * Sabre writer with the hooks the generated types need.
 */
class Writer extends \Sabre\Xml\Writer
{
    /**
     * Whether this writer builds the document in memory instead of writing it to a file.
     *
     * Set by Client::openSabreWriter(), read by Client::closeSabreWriter().
     */
    public bool $inMemory = false;

    /**
     * The default namespace (xmlns) in scope for the element currently open.
     */
    private ?string $defaultNamespace = null;

    /**
     * The default namespace in scope outside of each open element, innermost last.
     *
     * @var array<int, string|null>
     */
    private array $defaultNamespaceStack = [];

    /**
     * Whether the element currently open declares its default namespace itself.
     */
    private bool $namespaceDeclaredHere = false;

    /**
     * Whether the element currently open is a global element at the document root, whose
     * direct children declare their namespace themselves (see writeRootNamespace()).
     */
    private bool $isGlobal = false;

    /**
     * Whether the parent of the element currently open is such an element.
     */
    private bool $parentIsGlobal = false;

    /**
     * isGlobal of the parent of each open element, innermost last.
     *
     * @var array<int, bool>
     */
    private array $isGlobalStack = [];

    /**
     * Suppresses the xmlns declarations sabre writes automatically.
     *
     * On the first element sabre emits every entry of the namespaceMap as an xmlns
     * attribute. The generated types write their own xmlns in xmlSerialize(), so
     * without this the declaration would end up in the document twice.
     */
    public function markNamespacesWritten(): void
    {
        $this->namespacesWritten = true;
    }

    /**
     * Declares $namespace as default namespace on the open element, unless it already is.
     *
     * The generated types call this for every element they write. Declaring it only
     * where it changes keeps it on the root of a document instead of repeating it on
     * every element below. Direct children of a global element always declare it, see
     * writeRootNamespace().
     */
    public function writeDefaultNamespace(string $namespace): void
    {
        if ($this->parentIsGlobal) {
            $this->declareNamespaceHere($namespace);
        } elseif ($this->defaultNamespace !== $namespace) {
            $this->writeAttribute('xmlns', $namespace);
        }
    }

    /**
     * Declares $namespace as default namespace on the open element even if it is already
     * in scope. On the root element of the document, its direct children are made to
     * declare their namespace themselves as well.
     *
     * Called for global elements such as API requests and responses or a
     * BulkDataExchangeRequests file. eBay processes the requests of such a file one by
     * one and rejects those without their own xmlns; marking the file root covers its
     * requests even when they are built from the *Type classes, which are no global
     * elements themselves.
     */
    public function writeRootNamespace(string $namespace): void
    {
        $this->declareNamespaceHere($namespace);
        $this->isGlobal = 1 === \count($this->defaultNamespaceStack);
    }

    private function declareNamespaceHere(string $namespace): void
    {
        if (!$this->namespaceDeclaredHere || $this->defaultNamespace !== $namespace) {
            $this->writeAttribute('xmlns', $namespace);
        }
    }

    public function startElement($name): bool
    {
        // A Clark-notation name is opened through startElementNs(), or through this
        // method again with the local name, and is tracked there.
        if ('{' !== $name[0]) {
            $this->enter();
        }

        return parent::startElement($name);
    }

    public function startElementNs(?string $prefix, string $name, ?string $namespace): bool
    {
        $this->enter();
        $this->namespaceDeclaredHere = null === $prefix && null !== $namespace;
        if ($this->namespaceDeclaredHere) {
            $this->defaultNamespace = $namespace;
        }

        return parent::startElementNs($prefix, $name, $namespace);
    }

    public function endElement(): bool
    {
        $this->leave();

        return parent::endElement();
    }

    public function fullEndElement(): bool
    {
        $this->leave();

        return parent::fullEndElement();
    }

    private function enter(): void
    {
        $this->defaultNamespaceStack[] = $this->defaultNamespace;
        $this->isGlobalStack[] = $this->parentIsGlobal = $this->isGlobal;
        $this->isGlobal = false;
        $this->namespaceDeclaredHere = false;
    }

    private function leave(): void
    {
        $this->defaultNamespace = array_pop($this->defaultNamespaceStack);
        $this->isGlobal = (bool) array_pop($this->isGlobalStack);
        $this->parentIsGlobal = (bool) end($this->isGlobalStack);
    }

    public function writeAttribute($name, $value): bool
    {
        if ('xmlns' === $name) {
            $this->defaultNamespace = $value;
            $this->namespaceDeclaredHere = true;
        }

        return parent::writeAttribute($name, $value);
    }
}
