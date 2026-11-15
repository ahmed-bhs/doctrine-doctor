---
layout: default
title: Analyzer Execution Inventory
parent: Advanced
nav_order: 3
---

# Analyzer Execution Inventory

This reference lists each built-in analyzer and the place where it runs. It is mainly for contributors deciding where a new analyzer belongs.

| Execution path | Count | Contract |
|---------------|------:|----------|
| Runtime profiler | 41 | `AnalyzerInterface` without a static marker |
| Code and mapping checks | 56 | `StaticAnalyzerInterface` (including `MetadataAnalyzerInterface`) |
| Optional database audits | 9 | `DatabaseAuditAnalyzerInterface` |

The bundle keeps `doctrine_doctor.analyzer` as the service registration tag. During container compilation, services marked static are sent to the CI command; other analyzer services are sent to the profiler. Database audits also implement the static contract, and the command skips them unless `--with-database` is set.

The lists below reflect the interfaces implemented by the current built-in analyzer classes. Parser helpers and configuration objects are excluded.

## Runtime profiler (41)

These checks need SQL or other context captured while a request runs.

- `BulkInsertAnalyzer`
- `BulkOperationAnalyzer`
- `CartesianProductAnalyzer`
- `DeepOffsetPaginationAnalyzer`
- `DivisionByZeroAnalyzer`
- `DQLInjectionAnalyzer`
- `DTOHydrationAnalyzer`
- `EagerLoadingAnalyzer`
- `EntityManagerClearAnalyzer`
- `FilteredFetchJoinAnalyzer`
- `FindAllAnalyzer`
- `FlushInLoopAnalyzer`
- `FunctionOnPredicateColumnAnalyzer`
- `GetReferenceAnalyzer`
- `HydrationAnalyzer`
- `ImplicitTypeConversionAnalyzer`
- `IneffectiveLikeAnalyzer`
- `JoinOptimizationAnalyzer`
- `JoinTypeConsistencyAnalyzer`
- `LazyLoadingAnalyzer`
- `MissingIndexAnalyzer`
- `MissingTransactionOnBatchAnalyzer`
- `MissingVersionFieldForConcurrencyAnalyzer`
- `NestedRelationshipN1Analyzer`
- `NotInSubqueryAnalyzer`
- `NPlusOneAnalyzer`
- `NPlusOneSqlAnalyzer`
- `NullComparisonAnalyzer`
- `OrderByNullableLeadingColumnAnalyzer`
- `OrderByWithoutLimitAnalyzer`
- `PaginationWithoutOrderByAnalyzer`
- `PartialObjectAnalyzer`
- `QueryBuilderBestPracticesAnalyzer`
- `QueryCachingOpportunityAnalyzer`
- `SetMaxResultsWithCollectionJoinAnalyzer`
- `SlowQueryAnalyzer`
- `SQLInjectionInRawQueriesAnalyzer`
- `StructuralMissingIndexAnalyzer`
- `TransactionBoundaryAnalyzer`
- `UnusedEagerLoadAnalyzer`
- `YearFunctionOptimizationAnalyzer`

## Code and mapping checks (56)

These checks can run without SQL from the current web request.

- `BidirectionalConsistencyAnalyzer`
- `BlameableTraitAnalyzer`
- `CascadeAllAnalyzer`
- `CascadeConfigurationAnalyzer`
- `CascadePersistOnIndependentEntityAnalyzer`
- `CascadeRemoveOnIndependentEntityAnalyzer`
- `ClassTableInheritanceDepthAnalyzer`
- `ClassTableInheritanceThinSubclassAnalyzer`
- `CollectionFilteredInPhpAnalyzer`
- `CollectionInitializationAnalyzer`
- `CompositeKeyComplexityAnalyzer`
- `DecimalPrecisionAnalyzer`
- `DenormalizedAggregateWithoutLockingAnalyzer`
- `DiscriminatorColumnAnalyzer`
- `DoctrineCacheAnalyzer`
- `DuplicatePrivateFieldInHierarchyAnalyzer`
- `EagerLoadingMappingAnalyzer`
- `EmbeddableMutabilityAnalyzer`
- `EmbeddableWithoutValueObjectAnalyzer`
- `EntityManagerInEntityAnalyzer`
- `EntityManagerInServiceAnalyzer`
- `EntityStateConsistencyAnalyzer`
- `FinalEntityAnalyzer`
- `FloatForMoneyAnalyzer`
- `FloatInMoneyEmbeddableAnalyzer`
- `FlushInEventListenerAnalyzer`
- `ForeignKeyMappingAnalyzer`
- `GedmoExtensionPerformanceAnalyzer`
- `HardcodedDatabaseCredentialsAnalyzer`
- `InheritanceTypeOnNonRootEntityAnalyzer`
- `InsecureRandomAnalyzer`
- `JoinColumnNonPrimaryKeyAnalyzer`
- `LazyGhostObjectsDisabledAnalyzer`
- `MappedSuperclassAsTargetEntityAnalyzer`
- `MappedSuperclassOneToManyAnalyzer`
- `MissingEmbeddableOpportunityAnalyzer`
- `MissingOrphanRemovalOnCompositionAnalyzer`
- `NamingConventionAnalyzer`
- `NullablePrimaryKeyAnalyzer`
- `OnDeleteCascadeMismatchAnalyzer`
- `OneToOneInverseSideAnalyzer`
- `OrphanRemovalWithoutCascadeRemoveAnalyzer`
- `OverprivilegedDatabaseUserAnalyzer`
- `PrimaryKeyStrategyAnalyzer`
- `PropertyTypeMismatchAnalyzer`
- `RiskyMigrationAnalyzer`
- `SensitiveDataExposureAnalyzer`
- `SingleTableInheritanceNullableColumnAnalyzer`
- `SingleTableInheritanceSparseTableAnalyzer`
- `SoftDeleteableTraitAnalyzer`
- `SQLInjectionInRawQueriesSourceAnalyzer`
- `StringDefaultExpressionAnalyzer`
- `TimestampableTraitAnalyzer`
- `TypeHintMismatchAnalyzer`
- `UniqueEntityWithoutDatabaseIndexAnalyzer`
- `UnusedInverseSideAnalyzer`

## Optional database audits (9)

The command includes these only when run with `--with-database`.

- `CharsetAnalyzer`
- `CollationAnalyzer`
- `ColumnTypeAnalyzer`
- `ConnectionPoolingAnalyzer`
- `InnoDBEngineAnalyzer`
- `ManyToManyWithExtraColumnsAnalyzer`
- `MigrationStateAnalyzer`
- `StrictModeAnalyzer`
- `TimeZoneAnalyzer`
