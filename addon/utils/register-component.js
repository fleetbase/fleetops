import { dasherize } from '@ember/string';

export default function registerComponent(owner, componentClass) {
    const registrationName = `component:${dasherize(componentClass.name).replace('-component', '')}`;
    if (!owner.hasRegistration(registrationName)) {
        owner.register(registrationName, componentClass);
    }
}
