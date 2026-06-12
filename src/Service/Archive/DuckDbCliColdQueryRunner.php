<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

final readonly class DuckDbCliColdQueryRunner implements ColdQueryRunnerInterface
{
    public function __construct(
        private ArchiveObjectStorageInterface $storage,
        private string $duckDbBinary,
        private string $tempDirectory,
        private ArchiveCommandRunnerInterface $runner = new ProcessArchiveCommandRunner(),
        private int $timeoutSeconds = 120,
        private int $maxScannedObjects = 500,
        private int $maxResultBytes = 10485760,
    ) {
        if (trim($duckDbBinary) === '') {
            throw new \InvalidArgumentException('DuckDB binary path is required.');
        }

        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('DuckDB cold query timeout seconds must be positive.');
        }

        if ($maxScannedObjects <= 0) {
            throw new \InvalidArgumentException('DuckDB cold query scanned object limit must be positive.');
        }

        if ($maxResultBytes <= 0) {
            throw new \InvalidArgumentException('DuckDB cold query result byte limit must be positive.');
        }
    }

    public function run(ColdQueryExecutionRequest $request): ColdQueryExecutionResult
    {
        $sql = $this->safeSql($request->sql);
        if (count($request->objectKeys) > $this->maxScannedObjects) {
            throw new \InvalidArgumentException('DuckDB cold query scanned object limit exceeded.');
        }

        if ($request->objectKeys === []) {
            $this->storage->put($request->resultObjectKey, '[]', 'application/json');

            return new ColdQueryExecutionResult(
                resultObjectKey: $request->resultObjectKey,
                resultFormat: 'json',
                rowCount: 0,
                scannedObjectKeys: [],
            );
        }

        $workspace = $this->workspace();
        $resultPath = $workspace . DIRECTORY_SEPARATOR . 'result.json';

        try {
            $parquetPaths = $this->downloadObjects($request->objectKeys, $workspace);
            $boundSql = $this->bindParameters($sql, $request->parameters);
            $result = $this->runner->run(
                [$this->duckDbBinary, '-batch'],
                $this->querySql($parquetPaths, $boundSql, $resultPath),
                $this->timeoutSeconds,
            );
            if ($result->exitCode !== 0 || !is_file($resultPath)) {
                throw new \RuntimeException('DuckDB cold query failed: ' . trim($result->stderr . "\n" . $result->stdout));
            }

            $body = (string) file_get_contents($resultPath);
            if (strlen($body) > $this->maxResultBytes) {
                throw new \RuntimeException('DuckDB cold query result exceeds configured byte limit.');
            }

            $this->storage->put($request->resultObjectKey, $body, 'application/json');

            return new ColdQueryExecutionResult(
                resultObjectKey: $request->resultObjectKey,
                resultFormat: 'json',
                rowCount: $this->rowCount($body),
                scannedObjectKeys: $request->objectKeys,
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    private function safeSql(string $sql): string
    {
        $trimmed = trim($sql);
        if (!preg_match('/^select\b/i', $trimmed) || str_contains(rtrim($trimmed, ';'), ';')) {
            throw new \InvalidArgumentException('DuckDB cold query only accepts a single SELECT statement.');
        }

        if (!preg_match('/\bfrom\s+archive\b/i', $trimmed)) {
            throw new \InvalidArgumentException('DuckDB cold query must read from the archive view.');
        }

        return rtrim($trimmed, ';');
    }

    /**
     * @param list<string> $objectKeys
     * @return list<string>
     */
    private function downloadObjects(array $objectKeys, string $workspace): array
    {
        $paths = [];
        foreach (array_values($objectKeys) as $index => $objectKey) {
            $path = $workspace . DIRECTORY_SEPARATOR . 'part-' . $index . '.parquet';
            file_put_contents($path, $this->storage->get($objectKey));
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @param list<mixed> $parameters
     */
    private function bindParameters(string $sql, array $parameters): string
    {
        foreach ($parameters as $parameter) {
            $position = strpos($sql, '?');
            if ($position === false) {
                throw new \InvalidArgumentException('DuckDB cold query received more parameters than placeholders.');
            }

            $sql = substr_replace($sql, $this->parameterLiteral($parameter), $position, 1);
        }

        if (str_contains($sql, '?')) {
            throw new \InvalidArgumentException('DuckDB cold query has unbound parameter placeholders.');
        }

        return $sql;
    }

    private function parameterLiteral(mixed $parameter): string
    {
        if ($parameter === null) {
            return 'NULL';
        }

        if (is_int($parameter) || is_float($parameter)) {
            return (string) $parameter;
        }

        if (is_bool($parameter)) {
            return $parameter ? 'TRUE' : 'FALSE';
        }

        if (!is_scalar($parameter)) {
            throw new \InvalidArgumentException('DuckDB cold query parameters must be scalar values.');
        }

        return "'" . str_replace("'", "''", (string) $parameter) . "'";
    }

    /**
     * @param list<string> $parquetPaths
     */
    private function querySql(array $parquetPaths, string $sql, string $resultPath): string
    {
        $files = implode(', ', array_map(
            fn (string $path): string => "'" . $this->sqlLiteral($path) . "'",
            $parquetPaths,
        ));
        return sprintf(
            "CREATE OR REPLACE VIEW archive AS SELECT * FROM read_parquet([%s], union_by_name=true);\n"
            . "COPY (%s) TO '%s' (FORMAT JSON, ARRAY true);\n",
            $files,
            $sql,
            $this->sqlLiteral($resultPath),
        );
    }

    private function rowCount(string $json): int
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 0;
        }

        return is_array($decoded) ? count($decoded) : 0;
    }

    private function workspace(): string
    {
        $base = rtrim($this->tempDirectory, DIRECTORY_SEPARATOR);
        if ($base === '') {
            throw new \InvalidArgumentException('DuckDB cold query temp directory is required.');
        }

        if (!is_dir($base) && !mkdir($base, 0777, true) && !is_dir($base)) {
            throw new \RuntimeException('Unable to create DuckDB cold query temp directory.');
        }

        $workspace = $base . DIRECTORY_SEPARATOR . 'cold-query-' . bin2hex(random_bytes(8));
        if (!mkdir($workspace, 0777, true) && !is_dir($workspace)) {
            throw new \RuntimeException('Unable to create DuckDB cold query workspace.');
        }

        return $workspace;
    }

    private function sqlLiteral(string $value): string
    {
        return str_replace("'", "''", str_replace('\\', '/', $value));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
