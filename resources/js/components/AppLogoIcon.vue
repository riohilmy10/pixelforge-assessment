<script setup lang="ts">
defineOptions({
    inheritAttrs: false,
});
</script>

<template>
    <img src="/logo.png" alt="" class="object-contain" v-bind="$attrs" />
</template>
