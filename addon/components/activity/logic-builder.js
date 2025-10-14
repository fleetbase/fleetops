import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { isNone } from '@ember/utils';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';

export default class ActivityLogicBuilderComponent extends Component {
    @service intl;
    @tracked logic = [];

    /**
     * Returns available logic types used by the logic builder.
     *
     * @readonly
     * @memberof LogicBuilderService
     * @returns {Array<{type: string, description: string}>}
     */
    get types() {
        return [
            {
                type: 'and',
                description: this.intl?.t?.('activity.form.logic-builder.types.and') ?? 'Logical AND: True if all conditions are true',
            },
            {
                type: 'or',
                description: this.intl?.t?.('activity.form.logic-builder.types.or') ?? 'Logical OR: True if at least one condition is true',
            },
            {
                type: 'not',
                description: this.intl?.t?.('activity.form.logic-builder.types.not') ?? 'Logical NOT: Inverts the truth value of the condition',
            },
            {
                type: 'if',
                description: this.intl?.t?.('activity.form.logic-builder.types.if') ?? 'Conditional logic: Executes the following action if the condition is true',
            },
        ];
    }

    /**
     * Returns available comparison and logical operators used in the logic builder.
     *
     * @readonly
     * @memberof LogicBuilderService
     * @returns {Object}
     */
    get operators() {
        return {
            equal: {
                symbol: '==',
                description: this.intl?.t?.('activity.form.logic-builder.operators.equal') ?? 'Checks if two values are equal',
                operandType: 'any',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            notEqual: {
                symbol: '!=',
                description: this.intl?.t?.('activity.form.logic-builder.operators.not-equal') ?? 'Checks if two values are not equal',
                operandType: 'any',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            greaterThan: {
                symbol: '>',
                description: this.intl?.t?.('activity.form.logic-builder.operators.greater-than') ?? 'Checks if a value is greater than another',
                operandType: 'number',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            lessThan: {
                symbol: '<',
                description: this.intl?.t?.('activity.form.logic-builder.operators.less-than') ?? 'Checks if a value is less than another',
                operandType: 'number',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            greaterThanOrEqual: {
                symbol: '>=',
                description: this.intl?.t?.('activity.form.logic-builder.operators.greater-than-or-equal') ?? 'Checks if a value is greater than or equal to another',
                operandType: 'number',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            lessThanOrEqual: {
                symbol: '<=',
                description: this.intl?.t?.('activity.form.logic-builder.operators.less-than-or-equal') ?? 'Checks if a value is less than or equal to another',
                operandType: 'number',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            exists: {
                symbol: 'exists',
                description: this.intl?.t?.('activity.form.logic-builder.operators.exists') ?? 'Checks if a field or value exists',
                operandType: 'any',
                inputCount: 1,
                leftOperand: false,
                rightOperand: true,
            },
            has: {
                symbol: 'has',
                description: this.intl?.t?.('activity.form.logic-builder.operators.has') ?? 'Checks if an object has a specific property or if a collection contains a specific element',
                operandType: 'object/array',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            contains: {
                symbol: 'contains',
                description: this.intl?.t?.('activity.form.logic-builder.operators.contains') ?? 'Checks if a string contains another string, or if an array includes a specific element',
                operandType: 'string/array',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            beginsWith: {
                symbol: 'begins with',
                description: this.intl?.t?.('activity.form.logic-builder.operators.begins-with') ?? 'Checks if a string begins with a specified substring',
                operandType: 'string',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            endsWith: {
                symbol: 'ends with',
                description: this.intl?.t?.('activity.form.logic-builder.operators.ends-with') ?? 'Checks if a string ends with a specified substring',
                operandType: 'string',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            in: {
                symbol: 'in',
                description: this.intl?.t?.('activity.form.logic-builder.operators.in') ?? 'Checks if a value is within a given set or range',
                operandType: 'any',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            notIn: {
                symbol: 'not in',
                description: this.intl?.t?.('activity.form.logic-builder.operators.not-in') ?? 'Checks if a value is not within a given set or range',
                operandType: 'any',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            and: {
                symbol: '&&',
                description: this.intl?.t?.('activity.form.logic-builder.operators.and') ?? 'Logical AND, true if both operands are true',
                operandType: 'boolean',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            or: {
                symbol: '||',
                description: this.intl?.t?.('activity.form.logic-builder.operators.or') ?? 'Logical OR, true if at least one operand is true',
                operandType: 'boolean',
                inputCount: 2,
                leftOperand: true,
                rightOperand: true,
            },
            not: {
                symbol: '!',
                description: this.intl?.t?.('activity.form.logic-builder.operators.not') ?? 'Logical NOT, inverts the truth value',
                operandType: 'boolean',
                inputCount: 1,
                leftOperand: false,
                rightOperand: true,
            },
        };
    }

    constructor(owner, { activity }) {
        super(...arguments);
        this.logic = getWithDefault(activity, 'logic', []);
    }

    @action addLogic() {
        this.logic = [{ type: 'if', conditions: [this.createCondition()] }, ...this.logic];
    }

    @action selectLogicType({ type }, index) {
        this.updateLogic((item, idx) => {
            if (idx === index) {
                let updatedItem = { ...item, type: type };
                updatedItem.conditions = isArray(updatedItem.conditions) ? updatedItem.conditions : [this.createCondition()];
                return updatedItem;
            }
            return item;
        });
    }

    @action removeLogic(index) {
        this.logic = this.logic.filter((_, i) => i !== index);
        this.updateLogic();
    }

    @action addCondition(index) {
        this.updateLogic((item, idx) => {
            if (idx === index) {
                let updatedItem = { ...item };
                if (isArray(updatedItem.conditions)) {
                    updatedItem.conditions = [this.createCondition(), ...updatedItem.conditions];
                } else {
                    updatedItem.conditions = [this.createCondition()];
                }
                return updatedItem;
            }
            return item;
        });
    }

    @action removeCondition(index, conditionIndex) {
        this.updateLogic((item, idx) => {
            if (idx === index) {
                const { conditions, ...rest } = item;
                const updatedConditions = conditions.filter((_, cIdx) => cIdx !== conditionIndex);

                return { ...rest, conditions: updatedConditions };
            }

            return item;
        });

        // If logic has no conditions remove the logic
        const logic = this.logic[index];
        if (logic && logic.conditions?.length === 0) {
            this.removeLogic(index);
        }
    }

    @action selectConditionOperator(index, conditionIndex, key) {
        this.updateLogic((item, idx) => {
            if (idx === index) {
                const updatedConditions = item.conditions.map((condition, cIdx) => {
                    if (cIdx === conditionIndex) {
                        return { ...condition, operator: key };
                    }
                    return condition;
                });

                return { ...item, conditions: updatedConditions };
            }

            return item;
        });
    }

    @action trackConditionInputChanges() {
        this.updateLogic(this.logic);
    }

    @action updateLogic(logic) {
        if (typeof logic === 'function') {
            this.logic = this.logic.map(logic);
        } else if (!isNone(logic)) {
            this.logic = logic;
        }

        if (typeof this.args.onChange === 'function') {
            this.args.onChange(this.logic);
        }
    }

    createCondition() {
        return {
            field: '',
            operator: 'equal',
            value: '',
        };
    }
}
