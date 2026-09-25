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
| Runtime profiler | 40 | `AnalyzerInterface` without a static marker |
| Code and mapping checks | 52 | `StaticAnalyzerInterface` (including `MetadataAnalyzerInterface`) |
| Optional database audits | 8 | `DatabaseAuditAnalyzerInterface` |

The bundle keeps `doctrine_doctor.analyzer` as the service registration tag. During container compilation, services marked static are sent to the CI command; other analyzer services are sent to the profiler. Database audits also implement the static contract, and the command skips them unless `--with-database` is set.

The lists below reflect the interfaces implemented by the current built-in analyzer classes. Parser helpers and configuration objects are excluded.

## Runtime profiler (40)

These checks need SQL or other context captured while a request runs.

- `BulkInsertAnalyzer`
- `BulkOperationAnalyzer`
- `CartesianProductAnalyzer`
- `DQLInjectionAnalyzer`
- `DTOHydrationAnalyzer`
- `DeepOffsetPaginationAnalyzer`
- `DivisionByZeroAnalyzer`
- `EagerLoadingAnalyzer`
- `EntityManagerClearAnalyzer`
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
- `NPlusOneAnalyzer`
- `NPlusOneSqlAnalyzer`
- `NestedRelationshipN1Analyzer`
- `NotInSubqueryAnalyzer`
- `NullComparisonAnalyzer`
- `OrderByNullableLeadingColumnAnalyzer`
- `OrderByWithoutLimitAnalyzer`
- `PaginationWithoutOrderByAnalyzer`
- `PartialObjectAnalyzer`
- `QueryBuilderBestPracticesAnalyzer`
- `QueryCachingOpportunityAnalyzer`
- `SQLInjectionInRawQueriesAnalyzer`
- `SetMaxResultsWithCollectionJoinAnalyzer`
- `SlowQueryAnalyzer`
- `StructuralMissingIndexAnalyzer`
- `TransactionBoundaryAnalyzer`
- `UnusedEagerLoadAnalyzer`
- `YearFunctionOptimizationAnalyzer`

## Code and mapping checks (52)

These checks can run without SQL from the current web request.

- `BidirectionalConsistencyAnalyzer`
- `BlameableTraitAnalyzer`
- `CascadeAllAnalyzer`
- `CascadeConfigurationAnalyzer`
- `CascadePersistOnIndependentEntityAnalyzer`
- `CascadeRemoveOnIndependentEntityAnalyzer`
- `ClassTableInheritanceDepthAnalyzer`
- `ClassTableInheritanceThinSubclassAnalyzer`
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
- `SQLInjectionInRawQueriesSourceAnalyzer`
- `SensitiveDataExposureAnalyzer`
- `SingleTableInheritanceNullableColumnAnalyzer`
- `SingleTableInheritanceSparseTableAnalyzer`
- `SoftDeleteableTraitAnalyzer`
- `StringDefaultExpressionAnalyzer`
- `TimestampableTraitAnalyzer`
- `TypeHintMismatchAnalyzer`
- `UniqueEntityWithoutDatabaseIndexAnalyzer`

## Optional database audits (8)

The command includes these only when run with `--with-database`.

- `CharsetAnalyzer`
- `CollationAnalyzer`
- `ColumnTypeAnalyzer`
- `ConnectionPoolingAnalyzer`
- `InnoDBEngineAnalyzer`
- `ManyToManyWithExtraColumnsAnalyzer`
- `StrictModeAnalyzer`
- `TimeZoneAnalyzer`

---

**[← Architecture](architecture)** | **[Template Security →](template-security)**
