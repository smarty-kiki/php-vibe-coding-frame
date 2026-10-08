# up
create table if not exists `demo` (
    `id` bigint unsigned not null,
    `version` int not null,
    `create_time` datetime(3) default null,
    `update_time` datetime(3) default null,
    `delete_time` datetime(3) default null,
    `name` varchar(255) default null,
    `note` varchar(255) default null,
    primary key (`id`)
) engine=innodb default charset=utf8mb4;

# down
drop table `demo`;
