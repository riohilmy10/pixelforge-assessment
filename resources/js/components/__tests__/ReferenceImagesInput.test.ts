import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ReferenceImagesInput from '@/components/ReferenceImagesInput.vue';

function addButton(wrapper: ReturnType<typeof mount>) {
    return wrapper
        .findAll('button')
        .find((button) => button.text() === 'Add image');
}

describe('ReferenceImagesInput', () => {
    it('adds a blank row until the backend-provided maximum is reached', async () => {
        const wrapper = mount(ReferenceImagesInput, {
            props: { modelValue: [''], max: 2 },
        });

        await addButton(wrapper)?.trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['', '']]);

        await wrapper.setProps({ modelValue: ['', ''] });
        expect(addButton(wrapper)?.attributes('disabled')).toBeDefined();

        await addButton(wrapper)?.trigger('click');
        expect(wrapper.emitted('update:modelValue')).toHaveLength(1);
    });

    it('keeps one blank row when the last reference is removed', async () => {
        const wrapper = mount(ReferenceImagesInput, {
            props: { modelValue: ['https://example.com/a.png'] },
        });

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Remove')
            ?.trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['']]);
    });

    it('shows the provider token cost for each reference image', () => {
        const wrapper = mount(ReferenceImagesInput, {
            props: { modelValue: [''], tokenCost: 765 },
        });

        expect(wrapper.text()).toContain('765 input image tokens');
        expect(wrapper.text()).toContain('included in the estimate above');
    });
});
