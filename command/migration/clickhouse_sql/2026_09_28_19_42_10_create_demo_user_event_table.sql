# up
create table if not exists `demo_user_event` (
    `event_date` Date,
    `event_time` DateTime,
    `user_id` UInt64,
    `event_type` LowCardinality(String),
    `channel` LowCardinality(String),
    `amount` Decimal(18, 2),
    `duration_ms` UInt32,
    `props` String,
    `create_time` DateTime
) engine = MergeTree()
partition by toYYYYMM(`event_date`)
order by (`event_date`, `event_type`, `user_id`)

# down
drop table if exists `demo_user_event`
